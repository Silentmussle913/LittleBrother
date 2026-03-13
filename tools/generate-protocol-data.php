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

require __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['config:', 'token:']);

$config = [];
if(isset($opts['config'])){
	$config = json_decode(file_get_contents($opts['config']), true);
}

$config['token'] ??= $opts['token'] ?? null;

$versionMap = $config['versions'] ?? [];
$outDir = rtrim($config['out_dir'] ?? 'resources/data/bedrock', '/');
$token = $config['token'] ?? null;

if(empty($versionMap)){
	echo "ERROR: No versions configured\n";
	exit(1);
}

ksort($versionMap);

$currentProtocol = max(array_keys($versionMap));

$cacheDir = __DIR__ . '/.data-cache';
if(!is_dir($cacheDir)){
	mkdir($cacheDir, 0755, true);
}

function githubRaw(string $branch, string $file, ?string $token) : string{

	global $cacheDir;

	$cacheKey = $cacheDir . '/' . md5("$branch:$file");

	if(file_exists($cacheKey)){
		return file_get_contents($cacheKey);
	}

	$url = "https://raw.githubusercontent.com/pmmp/BedrockData/$branch/$file";

	$headers = ['User-Agent: LittleBrother-DataGen/2.0'];

	if($token){
		$headers[] = "Authorization: Bearer $token";
	}

	$ctx = stream_context_create([
		'http' => [
			'header' => implode("\r\n", $headers),
			'timeout' => 30
		]
	]);

	$body = @file_get_contents($url, false, $ctx);

	if($body === false){
		throw new RuntimeException("Failed to fetch $url");
	}

	file_put_contents($cacheKey, $body);

	return $body;
}

function save(string $path, string $data) : void{

	$dir = dirname($path);

	if(!is_dir($dir)){
		mkdir($dir, 0755, true);
	}

	file_put_contents($path, $data);
}

echo "\n=== LittleBrother Protocol Data Generator ===\n\n";

foreach($versionMap as $protocol => $branch){

	$protocol = (int) $protocol;

	echo "Protocol $protocol ($branch)\n";

	$dir = "$outDir/$protocol";

	if(!is_dir($dir)){
		mkdir($dir, 0755, true);
	}

	/*
	 * CURRENT PROTOCOL
	 * skip
	 */

	if($protocol === $currentProtocol){
		continue;
	}

	/*
	 * canonical_block_states
	 */

	echo "  fetching canonical_block_states.nbt...\n";

	if(file_exists("$dir/canonical_block_states.nbt")){
		echo "  already exists, skipping...\n";
	}else{
		$data = githubRaw($branch, 'canonical_block_states.nbt', $token);

		save("$dir/canonical_block_states.nbt", $data);
	}

	/*
	 * block_state_meta_map
	 */

	echo "  fetching block_state_meta_map.json...\n";

	if(file_exists("$dir/block_state_meta_map.json")){
		echo "  already exists, skipping...\n";
	}else{
		$data = githubRaw($branch, 'block_state_meta_map.json', $token);

		save("$dir/block_state_meta_map.json", $data);
	}

	/*
	 * required_item_list
	 */

	echo "  fetching required_item_list.json...\n";

	if(file_exists("$dir/required_item_list.json")){
		echo "  already exists, skipping...\n";
	}else{
		$data = githubRaw($branch, 'required_item_list.json', $token);

		save("$dir/required_item_list.json", $data);
	}

	echo "  done\n\n";
}

echo "Finished\n";
echo "Output: $outDir\n\n";
