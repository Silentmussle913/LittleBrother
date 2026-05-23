#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2024 - present nicholass003
 *        _      _           _                ___   ___ ____
 *       (_)    | |         | |              / _ \ / _ \___ \
 *  _ __  _  ___| |__   ___ | | __ _ ___ ___| | | | | | |__) |
 * | '_ \| |/ __| '_ \ / _ \| |/ _` / __/ __| | | | | | |__ <
 * | | | | | (__| | | | (_) | | (_| \__ \__ \ |_| | |_| |__) |
 * |_| |_|_|\___|_| |_|\___/|_|\__,_|___/___/\___/ \___/____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author  nicholass003
 * @link    https://github.com/nicholass003/
 *
 *
 */

declare(strict_types=1);

const MINECRAFT_PREFIX = "minecraft:";

$opts = getopt("", ["config:", "token:", "old:", "new:", "schemas:", "out:"]);

if(isset($opts["config"])){
	runFromConfig($opts);
}elseif(isset($opts["old"], $opts["new"], $opts["schemas"], $opts["out"])){
	runSingle(
		(string) $opts["old"],
		(string) $opts["new"],
		(string) $opts["schemas"],
		(string) $opts["out"]
	);
}else{
	fwrite(STDERR, "Usage:\n");
	fwrite(STDERR, "  --config <path>\n");
	fwrite(STDERR, "  --old <palette> --new <palette> --schemas <dir> --out <file>\n");
	exit(1);
}

function runFromConfig(array $opts) : void{
	$configPath = (string) ($opts["config"] ?? "config.json");

	if(!file_exists($configPath)){
		throw new RuntimeException("Config not found: $configPath");
	}

	$config = json_decode(
		(string) file_get_contents($configPath),
		true,
		512,
		JSON_THROW_ON_ERROR
	);

	$outDir = rtrim((string) ($config["out_dir"] ?? "resources/data/item-upgrade-schema"), "/");
	$schemasDir = rtrim((string) ($config["schemas_dir"] ?? $outDir), "/");
	$cacheDir = rtrim((string) ($config["cache_dir"] ?? sys_get_temp_dir() . "/palette-cache"), "/");
	$token = (string) ($opts["token"] ?? $config["token"] ?? "");

	@mkdir($outDir, 0755, true);
	@mkdir($cacheDir, 0755, true);

	foreach($config["versions"] as $entry){
		$schemaId = (int) $entry["schema"];
		$from = (string) $entry["from"];
		$to = (string) $entry["to"];
		$fromProtocol = (string) $entry["from_protocol"];
		$toProtocol = (string) $entry["to_protocol"];
		$preview = (bool) ($entry["preview"] ?? false);

		$dataPath = $preview ? "preview" : "release";

		$oldPaletteUrl = "https://raw.githubusercontent.com/Kaooot/bedrock-network-data/master/$dataPath/$fromProtocol/item_palette.json";
		$newPaletteUrl = "https://raw.githubusercontent.com/Kaooot/bedrock-network-data/master/$dataPath/$toProtocol/item_palette.json";

		$oldPaletteFile = "$cacheDir/{$fromProtocol}_item_palette.json";
		$newPaletteFile = "$cacheDir/{$toProtocol}_item_palette.json";

		githubFetch($oldPaletteUrl, $oldPaletteFile, $token !== "" ? $token : null);
		githubFetch($newPaletteUrl, $newPaletteFile, $token !== "" ? $token : null);

		$outputFile = sprintf(
			"%s/%04d_%s_to_%s.json",
			$outDir,
			$schemaId,
			$from,
			$to
		);

		runSingle($oldPaletteFile, $newPaletteFile, $schemasDir, $outputFile);
	}
}

function runSingle(string $oldPalettePath, string $newPalettePath, string $schemasDir, string $outFile) : void{
	$oldPalette = loadPalette($oldPalettePath);
	$newPalette = loadPalette($newPalettePath);

	$addedItems = findAddedItems($oldPalette, $newPalette);

	if($addedItems === []){
		file_put_contents($outFile, "{}\n");
		return;
	}

	$familyRoots = buildFamilyRoots($newPalette);
	$remappedMetas = assignNewMetas($addedItems, $newPalette, $familyRoots);

	if($remappedMetas === []){
		file_put_contents($outFile, "{}\n");
		return;
	}

	ksort($remappedMetas, SORT_STRING);

	$output = ["remappedMetas" => []];

	foreach($remappedMetas as $root => $metaMap){
		ksort($metaMap, SORT_NUMERIC);

		foreach($metaMap as $meta => $itemName){
			$output["remappedMetas"][$root][(string) $meta] = $itemName;
		}
	}

	file_put_contents(
		$outFile,
		json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
	);
}

function loadPalette(string $file) : array{
	$data = json_decode(
		(string) file_get_contents($file),
		true,
		512,
		JSON_THROW_ON_ERROR
	);

	$result = [];

	foreach($data["items"] as $entry){
		$result[(string) $entry["name"]] = (int) $entry["id"];
	}

	return $result;
}

function findAddedItems(array $oldPalette, array $newPalette) : array{
	$result = [];

	foreach($newPalette as $itemName => $itemId){
		if(!isset($oldPalette[$itemName])){
			$result[$itemName] = $itemId;
		}
	}

	asort($result, SORT_NUMERIC);

	return $result;
}

function loadExistingMetas(string $schemasDir, string $outFile) : array{
	$result = [];

	if(!is_dir($schemasDir)){
		return $result;
	}

	$files = scandir($schemasDir, SCANDIR_SORT_ASCENDING);

	if($files === false){
		return $result;
	}

	foreach($files as $file){
		if($file === "." || $file === ".."){
			continue;
		}

		$path = $schemasDir . DIRECTORY_SEPARATOR . $file;

		if(realpath($path) === realpath($outFile)){
			continue;
		}

		if(!is_file($path) || !str_ends_with($file, ".json")){
			continue;
		}

		$data = json_decode(
			(string) file_get_contents($path),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		if(!isset($data["remappedMetas"])){
			continue;
		}

		foreach($data["remappedMetas"] as $root => $metaMap){
			foreach($metaMap as $meta => $variant){
				$result[(string) $root][(int) $meta] = (string) $variant;
			}
		}
	}

	return $result;
}

function assignNewMetas(array $addedItems, array $newPalette, array $familyRoots) : array{
	$result = [];
	$rootNames = array_keys($familyRoots);

	foreach($rootNames as $root){
		$familyItems = [];

		foreach($newPalette as $itemName => $itemId){
			$matchedRoot = resolveBestRoot($itemName, $rootNames);

			if($matchedRoot !== $root){
				continue;
			}

			$familyItems[$itemName] = $itemId;
		}

		asort($familyItems, SORT_NUMERIC);

		$meta = 0;

		foreach($familyItems as $itemName => $itemId){
			if(isset($addedItems[$itemName])){
				$result[$root][$meta] = $itemName;
			}

			$meta++;
		}
	}

	return $result;
}

/**
 * Resolves the most specific family root.
 */
function resolveBestRoot(string $itemName, array $roots) : ?string{
	$itemLocalName = substr($itemName, strlen(MINECRAFT_PREFIX));

	$bestRoot = null;
	$bestLength = 0;

	foreach($roots as $root){
		$rootLocalName = substr($root, strlen(MINECRAFT_PREFIX));

		if($itemLocalName === $rootLocalName){
			continue;
		}

		$suffix = "_" . $rootLocalName;

		if(!str_ends_with($itemLocalName, $suffix)){
			continue;
		}

		$length = strlen($suffix);

		if($length > $bestLength){
			$bestLength = $length;
			$bestRoot = $root;
		}
	}

	return $bestRoot;
}

function buildFamilyRoots(array $palette) : array{
	$roots = [];

	foreach($palette as $itemName => $itemId){
		$localName = substr($itemName, strlen(MINECRAFT_PREFIX));

		if(str_ends_with($localName, "_bucket")){
			$roots["minecraft:bucket"] = true;
		}elseif(str_ends_with($localName, "_spawn_egg")){
			$roots["minecraft:spawn_egg"] = true;
		}
	}

	return $roots;
}

function githubFetch(string $url, string $target, ?string $token) : void{
	$headers = ["User-Agent: LittleBrother-Schema-Generator"];

	if($token !== null && $token !== ""){
		$headers[] = "Authorization: Bearer $token";
	}

	$ctx = stream_context_create([
		"http" => [
			"header" => implode("\r\n", $headers),
			"timeout" => 30
		]
	]);

	$data = file_get_contents($url, false, $ctx);

	if($data === false){
		throw new RuntimeException("Download failed: $url");
	}

	file_put_contents($target, $data);
}
