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

use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;

require __DIR__ . '/../vendor/autoload.php';

const LOG_FILE = __DIR__ . '/../build/log/item-upgrade-schema-debug.log';

function logMsg(string $msg) : void{
	echo $msg . "\n";
	file_put_contents(LOG_FILE, "[" . date("Y-m-d H:i:s") . "] $msg\n", FILE_APPEND);
}

$opts = getopt('', ['config:', 'token:']);

$configPath = $opts['config'] ?? 'config.json';

if(!file_exists($configPath)){
	echo "config.json not found\n";
	exit(1);
}

$config = json_decode(file_get_contents($configPath), true);

$outDir = rtrim($config['out_dir'] ?? "resources/data/item-upgrade-schema", "/");
$token = $opts['token'] ?? $config['token'] ?? null;

if(!is_dir($outDir)){
	mkdir($outDir, 0755, true);
}

logMsg("\n=== Item Upgrade Schema Generator ===\n");

foreach($config["versions"] as $entry){
	$schemaId = (int) $entry["schema"];

	$from = $entry["from"];
	$to = $entry["to"];

	$fromProtocol = $entry["from_protocol"];
	$toProtocol = $entry["to_protocol"];

	$preview = $entry["preview"] ?? false;

	logMsg("Processing schema $schemaId ($from -> $to)");

	$dataPath = $preview ? "preview" : "release";

	$oldUrl = "https://raw.githubusercontent.com/Kaooot/bedrock-network-data/master/$dataPath/$fromProtocol/item_components.nbt";
	$newUrl = "https://raw.githubusercontent.com/Kaooot/bedrock-network-data/master/$dataPath/$toProtocol/item_components.nbt";

	logMsg("Old URL: $oldUrl");
	logMsg("New URL: $newUrl");

	$oldTmp = tempnam(sys_get_temp_dir(), "item_old_");
	$newTmp = tempnam(sys_get_temp_dir(), "item_new_");

	githubFetch($oldUrl, $oldTmp, $token);
	githubFetch($newUrl, $newTmp, $token);

	$oldItems = loadItems($oldTmp);
	$newItems = loadItems($newTmp);

	logMsg("Old items: " . count($oldItems));
	logMsg("New items: " . count($newItems));

	$renamed = detectRenamed($oldItems, $newItems);
	$remapped = detectMetaFlatten($oldItems, $newItems);

	logMsg("Renamed IDs: " . count($renamed));
	logMsg("Remapped metas: " . count($remapped));

	$schema = [];

	if($renamed){
		ksort($renamed);
		$schema["renamedIds"] = $renamed;
	}

	if($remapped){
		ksort($remapped);
		$schema["remappedMetas"] = $remapped;
	}

	$filename = sprintf(
		"%04d_%s_beta_to_%s_beta.json",
		$schemaId,
		$from,
		$to
	);

	file_put_contents(
		"$outDir/$filename",
		json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
	);

	unlink($oldTmp);
	unlink($newTmp);

	logMsg("Schema saved: $filename\n");
}

logMsg("Generation finished\n");

function githubFetch(string $url, string $target, ?string $token) : void{
	logMsg("Downloading: $url");

	$headers = ["User-Agent: BedrockSchemaGenerator"];

	if($token){
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
		throw new RuntimeException("Failed to fetch $url");
	}

	file_put_contents($target, $data);
}

function buildSignature(?CompoundTag $components) : string{
	if($components === null){
		return "";
	}

	$keys = array_keys($components->getValue());

	sort($keys, SORT_STRING);

	return md5(implode("|", $keys));
}

function loadItems(string $file) : array{
	$raw = file_get_contents($file);

	$decoded = ErrorToExceptionHandler::trapAndRemoveFalse(
		fn() => zlib_decode($raw)
	);

	$root = (new BigEndianNbtSerializer())
		->read($decoded)
		->mustGetCompoundTag();

	$items = [];

	foreach($root->getValue() as $name => $tag){
		if(!$tag instanceof CompoundTag){
			continue;
		}

		$id = $tag->getInt("id", -1);

		$components = $tag->getCompoundTag("components");

		$signature = buildSignature($components);

		$items[$name] = [
			"id" => $id,
			"signature" => $signature
		];
	}

	return $items;
}

function detectRenamed(array $old, array $new) : array{
	$result = [];

	$signatureMap = [];

	foreach($new as $name => $data){
		$signatureMap[$data["signature"]][] = $name;
	}

	foreach($old as $oldName => $oldData){
		if(isset($new[$oldName])){
			continue;
		}

		$sig = $oldData["signature"];

		$candidates = $signatureMap[$sig] ?? [];

		$bestScore = 0;
		$best = null;

		foreach($candidates as $candidate){
			similar_text($oldName, $candidate, $score);

			if($score > $bestScore){
				$bestScore = $score;
				$best = $candidate;
			}
		}

		if($best === null){
			foreach($new as $newName => $newData){
				similar_text($oldName, $newName, $score);

				if($score > 80){
					$best = $newName;
					break;
				}
			}
		}

		if($best !== null){
			$result[$oldName] = $best;

			logMsg("Detected rename: $oldName -> $best");
		}
	}

	return $result;
}

function detectMetaFlatten(array $old, array $new) : array{
	$result = [];

	foreach($old as $oldName => $_){
		$variants = [];

		foreach($new as $newName => $_){
			if(preg_match(
				"/^" . preg_quote($oldName, "/") . "_([0-9]+)$/",
				$newName,
				$m
			)){
				$variants[(int) $m[1]] = $newName;
			}
		}

		if(count($variants) > 1){
			ksort($variants);

			foreach($variants as $meta => $name){
				$result[$oldName][(string) $meta] = $name;
			}

			logMsg("Meta flatten detected: $oldName");
		}
	}

	return $result;
}
