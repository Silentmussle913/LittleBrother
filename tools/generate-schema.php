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

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

require __DIR__ . '/../vendor/autoload.php';

const DEBUG_LOG_FILE = __DIR__ . '/../build/log/schema-debug.log';

function debugLog(string $category, string $message) : void{
	$time = date('H:i:s');
	file_put_contents(
		DEBUG_LOG_FILE,
		"[$time][$category] $message\n",
		FILE_APPEND
	);
}

// Packets handled by ManualPacketHandler instead of SchemaTranslator.
// Generator still outputs fields for documentation, but adds 'manual' => true
// at the root schema entry. Add className here for packets too complex to
// translate via schema (variable-length opaque structures, full NBT, etc).
const MANUAL_PACKETS = [
	'StartGamePacket' => true,
	'CraftingDataPacket' => true,
	'InventoryTransactionPacket' => true,
	'PlayerListPacket' => true,
	'TextPacket' => true,
	'PlayerAuthInputPacket' => true,
	'MovePlayerPacket' => true,
	'ItemStackResponsePacket' => true,
	'SubChunkPacket' => true,
	'LevelChunkPacket' => true,
	'UpdateBlockPacket' => true,
	'UpdateSubChunkBlocksPacket' => true,
	'UpdateBlockSyncedPacket' => true,
	'InteractPacket' => true,
	'AnimatePacket' => true,
	'LoginPacket' => true,
];

const PACKETS_OVERRIDES = [
	'ItemStackRequestPacket' => [
		[
			'name' => 'requests',
			'type' => 'array',
			'countType' => 'uvarint',
			'entry' => [
				[
					'name' => 'value',
					'type' => 'item_stack_request',
				],
			],
		],
	],
];

const FIELD_OVERRIDES = [
	'AvailableCommandsPacket' => [
		'enumValues' => [
			[
				'type' => 'array',
				'countType' => 'uvarint',
				'entry' => [
					[
						'name' => 'value',
						'type' => 'string',
					],
				],
				'storeCountAs' => 'enumValuesCount',
			],
		],
		'enums.valueIndexes' => [
			[
				'type' => 'enums_value_indexes'
			]
		],
		'commandData.permission' => [
			[
				'until' => 860,
				'type' => 'u8',
			],
			[
				'since' => 898,
				'type' => 'string',
			],
		],
		'commandData.chainedSubCommandDataIndexes' => [
			[
				'type' => 'array',
				'countType' => 'uvarint',
				'entry' => [
					[
						'name' => 'value',
						'type' => 'le:u16',
						'until' => 860,
					],
					[
						'name' => 'value',
						'type' => 'le:u32',
						'since' => 898,
					],
				],
			],
		],
	],
	'ResourcePackStackPacket' => [
		'behaviorPackStack' => [
			[
				'type' => 'array',
				'until' => 860,
				'countType' => 'uvarint',
				'entry' => [
					[
						'name' => 'packId',
						'type' => 'string',
					],
					[
						'name' => 'version',
						'type' => 'string',
					],
					[
						'name' => 'subPackName',
						'type' => 'string',
					],
				]
			]
		]
	]
];

// Used for protocols not yet available in pmmp/BedrockProtocol.
// Fetches packet structure from Mojang bedrock-protocol-docs.
//
// Packet matching Mojang <-> pmmp:
//   1. Via $metaProperties["[cereal:packet]"] == packetId in ProtocolInfo
//   2. Fallback: name similarity (case-insensitive, strip "Packet" suffix)
// Map x-underlying-type (Mojang) → pmmp schema type string (without Compression)
const MOJANG_UNDERLYING_TYPE_MAP = [
	'uint8' => 'u8',
	'int8' => 'i8',
	'uint16' => 'le:u16',
	'int16' => 'le:i16',
	'uint32' => 'le:u32',
	'int32' => 'le:i32',
	'uint64' => 'le:u64',
	'int64' => 'le:i64',
	'float' => 'le:f32',
	'double' => 'le:f64',
	'bool' => 'bool',
	'boolean' => 'bool',
	'string' => 'string',
];

function mojangGet(string $path, ?string $token) : ?string{
	$url = "https://api.github.com/repos/Mojang/bedrock-protocol-docs/contents/$path";
	debugLog("MOJANG_FETCH", "GET $path");
	try{
		$data = githubGet($url, $token);
		if(!isset($data['content'])){
			debugLog("MOJANG_FETCH", "no content field | $path");
			return null;
		}
		$decoded = base64_decode(str_replace("\n", '', $data['content']), true);
		debugLog("MOJANG_FETCH", "ok | $path | " . strlen($decoded) . " bytes");
		return $decoded;
	}catch(Throwable $e){
		debugLog("MOJANG_FETCH", "exception | $path | " . $e->getMessage());
		return null;
	}
}

function mojangCachedGet(string $path, ?string $token) : ?string{
	$cacheKey = "mojang:$path";
	$cached = diskCacheGet($cacheKey);
	if($cached !== null){
		debugLog("MOJANG_CACHE", "hit | $path");
		return $cached;
	}
	$content = mojangGet($path, $token);
	if($content !== null){
		diskCacheSet($cacheKey, $content);
	}
	return $content;
}

function parseMojangNewPacketIds(string $markdown) : array{
	$packets = [];
	$inSection = false;

	foreach(explode("\n", $markdown) as $line){
		$trimmed = trim($line);

		if($trimmed === 'MinecraftPacketIds:'){
			$inSection = true;
			debugLog("MOJANG_PARSE", "enter section MinecraftPacketIds");
			continue;
		}

		if($inSection
			&& $trimmed !== ''
			&& str_ends_with($trimmed, ':')
			&& !preg_match('/^\s*(Added|Removed|Changed|Displaced)\s/', $trimmed)
		){
			debugLog("MOJANG_PARSE", "exit MinecraftPacketIds | next=$trimmed");
			$inSection = false;
			continue;
		}

		if(!$inSection) continue;

		if(preg_match('/^\s*Added\s+(\w+)\s+\((\d+)\)\s*\[/', $trimmed, $m)){
			if(in_array($m[1], ['EndId', 'EndID', 'Count', 'NumPackets'], true)) continue;
			$packets[$m[1]] = (int) $m[2];
			debugLog("MOJANG_PARSE", "new packet | {$m[1]} = {$m[2]}");
		}
	}

	return $packets;
}

function parseMojangRawChangelog(string $markdown) : array{
	$entries = [];
	$inSection = false;

	foreach(explode("\n", $markdown) as $line){
		$trimmed = trim($line);

		if(str_contains($trimmed, 'Raw Protocol Version Changelog')){
			$inSection = true;
			debugLog("MOJANG_PARSE", "enter section Raw Protocol Version Changelog");
			continue;
		}

		if(!$inSection) continue;
		if($trimmed === '' || str_starts_with($trimmed, '#')) continue;

		if(preg_match('/^(\d{3,4}):\s+(.+)$/', $trimmed, $m)){
			$entries[] = ['subversion' => (int) $m[1], 'description' => trim($m[2])];
			debugLog("MOJANG_PARSE", "subversion {$m[1]} | {$m[2]}");
		}
	}

	debugLog("MOJANG_PARSE", "total raw entries | " . count($entries));
	return $entries;
}

function mojangResolveType(string $underlyingType, array $serOpts) : string{
	$compressed = in_array('Compression', $serOpts, true);

	if($compressed){
		return match($underlyingType){
			'uint8', 'uint16', 'uint32' => 'uvarint',
			'int8', 'int16', 'int32' => 'varint',
			'uint64' => 'uvarlong',
			'int64' => 'varlong',
			default => MOJANG_UNDERLYING_TYPE_MAP[$underlyingType] ?? $underlyingType,
		};
	}

	return MOJANG_UNDERLYING_TYPE_MAP[$underlyingType] ?? $underlyingType;
}

function mojangStripQualifier(string $title) : string{
	$title = preg_replace('/^(struct|class|enum)\s+/i', '', $title);
	// Take only the part after "::" if present
	if(str_contains($title, '::')){
		$title = substr($title, strrpos($title, '::') + 2);
	}
	return $title;
}

function mojangRefToTypeString(string $refId, array $definitions) : string{
	$def = $definitions[$refId] ?? null;
	if($def === null){
		debugLog("MOJANG_REF", "ref not found in definitions | $refId");
		return 'unknown';
	}
	$title = mojangStripQualifier($def['title'] ?? $refId);
	return camelToSnake($title);
}

function mojangExpandDefinition(string $refId, array $definitions, string $fieldName, int $depth = 0) : array{
	if($depth > 3) return [['name' => $fieldName, 'type' => 'unknown']];

	$def = $definitions[$refId] ?? null;
	if($def === null) return [['name' => $fieldName, 'type' => 'unknown']];

	$title = mojangStripQualifier($def['title'] ?? $refId);
	$props = $def['properties'] ?? [];

	if(count($props) === 1){
		$innerDef = reset($props);
		$underlyingType = $innerDef['x-underlying-type'] ?? null;
		$serOpts = $innerDef['x-serialization-options'] ?? [];
		if($underlyingType !== null){
			$type = mojangResolveType($underlyingType, $serOpts);
			debugLog("MOJANG_EXPAND", "single-wrapper | $title → $fieldName:$type");
			return [['name' => $fieldName, 'type' => $type]];
		}
	}

	$typeStr = camelToSnake($title);
	debugLog("MOJANG_EXPAND", "multi-field | $title → $fieldName:$typeStr");
	return [['name' => $fieldName, 'type' => $typeStr]];
}

function parseMojangPacketJson(array $json, string $packetName) : array{
	$properties = $json['properties'] ?? [];
	$definitions = $json['definitions'] ?? [];

	if(empty($properties)){
		debugLog("MOJANG_JSON", "no properties | $packetName");
		return [];
	}

	$ordered = [];
	foreach($properties as $fieldName => $fieldDef){
		$ordinal = $fieldDef['x-ordinal-index'] ?? 9999;
		$ordered[] = ['fieldName' => $fieldName, 'def' => $fieldDef, 'ordinal' => $ordinal];
	}
	usort($ordered, fn($a, $b) => $a['ordinal'] <=> $b['ordinal']);

	$fields = [];

	foreach($ordered as $item){
		$rawName = $item['fieldName'];
		$def = $item['def'];
		$ordinal = $item['ordinal'];

		$snakeName = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $rawName));
		$snakeName = trim($snakeName, '_');

		debugLog("MOJANG_JSON", "$packetName.$snakeName | ordinal=$ordinal | raw='$rawName'");

		if(isset($def['oneOf'])){
			debugLog("MOJANG_JSON", "$packetName.$snakeName | oneOf union → manual_union");
			$fields[] = ['name' => $snakeName, 'type' => 'manual_union'];
			continue;
		}

		if(($def['type'] ?? '') === 'array'){
			$items = $def['items'] ?? [];
			$countUnderlyingType = $def['x-count-underlying-type'] ?? 'uint32';
			$countSerOpts = $def['x-count-serialization-options'] ?? ['Compression'];
			$countType = mojangResolveType($countUnderlyingType, $countSerOpts);

			if(isset($items['$ref'])){
				$refId = str_replace('#/definitions/', '', $items['$ref']);
				$entryTypeStr = mojangRefToTypeString($refId, $definitions);
				debugLog("MOJANG_JSON", "$packetName.$snakeName | array | count=$countType | entry ref=$entryTypeStr");
				$fields[] = [
					'name' => $snakeName,
					'type' => 'array',
					'countType' => $countType,
					'entry' => [['name' => 'value', 'type' => $entryTypeStr]],
				];
			}elseif(isset($items['x-underlying-type'])){
				$itemSerOpts = $items['x-serialization-options'] ?? [];
				$itemType = mojangResolveType($items['x-underlying-type'], $itemSerOpts);
				debugLog("MOJANG_JSON", "$packetName.$snakeName | array | count=$countType | entry scalar=$itemType");
				$fields[] = [
					'name' => $snakeName,
					'type' => 'array',
					'countType' => $countType,
					'entry' => [['name' => 'value', 'type' => $itemType]],
				];
			}elseif(($items['type'] ?? '') === 'string'){
				debugLog("MOJANG_JSON", "$packetName.$snakeName | array of string");
				$fields[] = [
					'name' => $snakeName,
					'type' => 'array',
					'countType' => $countType,
					'entry' => [['name' => 'value', 'type' => 'string']],
				];
			}else{
				debugLog("MOJANG_JSON", "$packetName.$snakeName | array | items unknown");
				$fields[] = [
					'name' => $snakeName,
					'type' => 'array',
					'countType' => $countType,
					'entry' => [],
				];
			}
			continue;
		}

		if(isset($def['$ref'])){
			$refId = str_replace('#/definitions/', '', $def['$ref']);
			$expanded = mojangExpandDefinition($refId, $definitions, $snakeName);
			foreach($expanded as $f){
				debugLog("MOJANG_JSON", "$packetName.$snakeName | ref expanded | {$f['name']}:{$f['type']}");
				$fields[] = $f;
			}
			continue;
		}

		if(isset($def['enum']) && ($def['type'] ?? '') === 'string'){
			$underlyingType = $def['x-underlying-type'] ?? 'uint8';
			$serOpts = $def['x-serialization-options'] ?? [];
			$mappedType = mojangResolveType($underlyingType, $serOpts);
			debugLog("MOJANG_JSON", "$packetName.$snakeName | enum | $underlyingType → $mappedType");
			$fields[] = ['name' => $snakeName, 'type' => $mappedType];
			continue;
		}

		if(($def['type'] ?? '') === 'boolean'
			|| ($def['x-underlying-type'] ?? '') === 'bool'
			|| ($def['x-underlying-type'] ?? '') === 'boolean'
		){
			debugLog("MOJANG_JSON", "$packetName.$snakeName | bool");
			$fields[] = ['name' => $snakeName, 'type' => 'bool'];
			continue;
		}

		if(isset($def['x-underlying-type'])){
			$serOpts = $def['x-serialization-options'] ?? [];
			$mappedType = mojangResolveType($def['x-underlying-type'], $serOpts);
			debugLog("MOJANG_JSON", "$packetName.$snakeName | scalar | {$def['x-underlying-type']} → $mappedType");
			$fields[] = ['name' => $snakeName, 'type' => $mappedType];
			continue;
		}

		if(($def['type'] ?? '') === 'string'){
			debugLog("MOJANG_JSON", "$packetName.$snakeName | plain string");
			$fields[] = ['name' => $snakeName, 'type' => 'string'];
			continue;
		}

		debugLog("MOJANG_JSON", "$packetName.$snakeName | unresolved | def=" . json_encode($def));
		$fields[] = ['name' => $snakeName, 'type' => 'unknown'];
	}

	debugLog("MOJANG_JSON", "$packetName | extracted " . count($fields) . " fields");
	return $fields;
}

function fetchMojangPacketData(string $packetName, ?string $token) : ?array{
	$path = "json/{$packetName}.json";
	debugLog("MOJANG_JSON_FETCH", "fetching | $path");

	$content = mojangCachedGet($path, $token);
	if($content === null){
		debugLog("MOJANG_JSON_FETCH", "not found | $packetName");
		return null;
	}

	$json = json_decode($content, true);
	if($json === null){
		debugLog("MOJANG_JSON_FETCH", "json decode failed | $packetName");
		return null;
	}

	$cerealId = $json['$metaProperties']['[cereal:packet]'] ?? null;
	if($cerealId !== null){
		debugLog("MOJANG_JSON_FETCH", "$packetName | cereal_id=$cerealId");
	}

	$fields = parseMojangPacketJson($json, $packetName);
	return ['fields' => $fields, 'cereal_id' => $cerealId];
}

function enumNameToPacketCandidates(string $enumName) : array{
	if(str_ends_with($enumName, 'Packet')){
		return [$enumName];
	}
	return [$enumName . 'Packet'];
}

function buildMojangPatchSnapshot(int $protocol, string $changelogFile, array $parsedByProtocol, array $protocols, ?string $token) : array{
	echo "  [mojang-patch] protocol $protocol ← $changelogFile\n";
	debugLog("MOJANG_PATCH", "=== build snapshot | protocol=$protocol | $changelogFile ===");

	$changelogContent = mojangCachedGet($changelogFile, $token);
	if($changelogContent === null){
		echo "  [mojang-patch] ERROR: failed to fetch $changelogFile\n";
		debugLog("MOJANG_PATCH", "failed to fetch changelog | $changelogFile");
		return [];
	}

	$newPacketIds = parseMojangNewPacketIds($changelogContent);
	$rawEntries = parseMojangRawChangelog($changelogContent);

	echo "  [mojang-patch] " . count($newPacketIds) . " new packets, " . count($rawEntries) . " subversion entries\n";

	$changedPackets = [];
	foreach($rawEntries as $entry){
		if(preg_match_all('/\b([A-Z][a-zA-Z]+Packet)\b/', $entry['description'], $m)){
			foreach($m[1] as $pName){
				$changedPackets[$pName][] = $entry['description'];
			}
		}
	}
	debugLog("MOJANG_PATCH", "changed packets from raw changelog | " . implode(', ', array_keys($changedPackets)));

	$prevProtocol = null;
	foreach(array_reverse($protocols) as $p){
		if($p < $protocol && isset($parsedByProtocol[$p])){
			$prevProtocol = $p;
			break;
		}
	}

	$snapshot = [];
	if($prevProtocol !== null){
		$snapshot = $parsedByProtocol[$prevProtocol];
		echo "  [mojang-patch] baseline from protocol $prevProtocol (" . count($snapshot) . " packets)\n";
		debugLog("MOJANG_PATCH", "baseline | protocol=$prevProtocol | " . count($snapshot) . " packets");
	}else{
		echo "  [mojang-patch] WARNING: no previous protocol found for baseline\n";
		debugLog("MOJANG_PATCH", "no previous protocol for baseline");
	}

	$idToPath = [];

	$patchedCount = 0;
	foreach($newPacketIds as $enumName => $enumValue){
		$candidates = enumNameToPacketCandidates($enumName);

		$found = false;
		foreach($candidates as $packetName){
			$data = fetchMojangPacketData($packetName, $token);
			if($data === null) continue;

			$syntheticPath = "src/{$packetName}.php";
			$constName = classNameToProtocolInfoConst($packetName);

			echo "  [mojang-patch] + new: $packetName"
				. ($data['cereal_id'] !== null ? " (cereal_id={$data['cereal_id']})" : "")
				. " → " . count($data['fields']) . " fields\n";
			debugLog("MOJANG_PATCH_NEW", "$packetName | enum=$enumName | enumVal=$enumValue | cereal_id=" . ($data['cereal_id'] ?? 'null') . " | fields=" . count($data['fields']));

			$snapshot[$syntheticPath] = [
				'className' => $packetName,
				'packetConst' => $constName,
				'fields' => $data['fields'],
				'_mojang_patch' => true,
				'_cereal_id' => $data['cereal_id'],
				'_needs_review' => empty($data['fields']),
			];

			if($data['cereal_id'] !== null){
				$idToPath[$data['cereal_id']] = $syntheticPath;
			}

			$patchedCount++;
			$found = true;
			break;
		}

		if(!$found){
			$packetName = $candidates[0];
			$syntheticPath = "src/{$packetName}.php";
			$constName = classNameToProtocolInfoConst($packetName);

			echo "  [mojang-patch] + new (no JSON): $packetName → marked manual\n";
			debugLog("MOJANG_PATCH_NEW", "$packetName | enum=$enumName | no JSON found");

			$snapshot[$syntheticPath] = [
				'className' => $packetName,
				'packetConst' => $constName,
				'fields' => [],
				'_mojang_patch' => true,
				'_cereal_id' => null,
				'_needs_review' => true,
			];
			$patchedCount++;
		}
	}

	foreach($changedPackets as $packetName => $descriptions){
		$enumWithoutPacket = preg_replace('/Packet$/', '', $packetName);
		if(isset($newPacketIds[$enumWithoutPacket]) || isset($newPacketIds[$packetName])){
			debugLog("MOJANG_PATCH_CHANGED", "$packetName | already processed as new, skip");
			continue;
		}

		$syntheticPath = "src/{$packetName}.php";
		if(!isset($snapshot[$syntheticPath])){
			debugLog("MOJANG_PATCH_CHANGED", "$packetName | not in baseline, skip");
			continue;
		}

		$data = fetchMojangPacketData($packetName, $token);
		if($data !== null){
			echo "  [mojang-patch] ~ changed: $packetName → " . count($data['fields']) . " fields (updated)\n";
			debugLog("MOJANG_PATCH_CHANGED", "$packetName | fields=" . count($data['fields']) . " | cereal_id=" . ($data['cereal_id'] ?? 'null'));
			$snapshot[$syntheticPath]['fields'] = $data['fields'];
			$snapshot[$syntheticPath]['_mojang_patch'] = true;
			$snapshot[$syntheticPath]['_cereal_id'] = $data['cereal_id'];
			$patchedCount++;
		}else{
			echo "  [mojang-patch] ~ changed: $packetName → no JSON, inherited from baseline\n";
			debugLog("MOJANG_PATCH_CHANGED", "$packetName | no JSON, inherited from baseline");
		}
	}

	debugLog("MOJANG_PATCH", "=== snapshot done | total=" . count($snapshot) . " | patched=$patchedCount ===");
	echo "  [mojang-patch] done: " . count($snapshot) . " total packets, $patchedCount new/updated\n";

	return $snapshot;
}

$opts = getopt('', ['config:', 'versions:', 'out:', 'token:', 'debug:']);

$config = [];
if(isset($opts['config'])){
	$raw = file_get_contents($opts['config']);
	if($raw === false){ echo "ERROR: Cannot read config file: {$opts['config']}\n"; exit(1); }
	$config = json_decode($raw, true);
}else{
	$config['versions'] = json_decode($opts['versions'] ?? '{}', true);
	$config['token'] = $opts['token'] ?? null;
}

$versionMap = $config['versions'] ?? [];
$outFile = __DIR__ . '/../build/schemas.php';
$githubToken = $config['token'] ?? null;
$mojangPatches = $config['mojang_patches'] ?? [];

// --debug=ResourcePackStackPacket,AvailableCommandsPacket
$debugPackets = isset($opts['debug']) ? array_flip(explode(',', $opts['debug'])) : [];

if(count($versionMap) < 2){
	echo "ERROR: Minimal 2 versions required.\n\n";
	echo "Create schema-config.json:\n";
	echo json_encode(['versions' => ['898' => '54.0.0+bedrock-1.21.130', '924' => '55.0.0+bedrock-1.26.0'], 'token' => 'ghp_xxxx_optional'], JSON_PRETTY_PRINT) . "\n";
	exit(1);
}

ksort($versionMap);
$protocols = array_map('intval', array_keys($versionMap));
$tags = array_values($versionMap);
$currentProtocol = end($protocols);

echo "\n=== LittleBrother Multi-Version Schema Generator ===\n\n";
echo "Versions (" . count($protocols) . "):\n";
foreach($protocols as $i => $p){
	$mark = ($p === $currentProtocol) ? " ← CURRENT" : "";
	echo "  protocol $p → tag {$tags[$i]}$mark\n";
}
echo "\n";

function githubGet(string $url, ?string $token) : array{
	$headers = ['User-Agent: LittleBrother-SchemaGen/2.0', 'Accept: application/vnd.github.v3+json'];
	if($token !== null) $headers[] = "Authorization: Bearer $token";
	$ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 20]]);
	$body = @file_get_contents($url, false, $ctx);
	if($body === false) throw new RuntimeException("Request failed: $url");
	foreach($http_response_header as $h){
		if(stripos($h, 'x-ratelimit-remaining:') === 0){
			$remaining = (int) trim(explode(':', $h, 2)[1]);
			if($remaining < 10) echo "  WARNING: GitHub rate limit $remaining remaining. Use --token.\n";
		}
	}
	return json_decode($body, true);
}

function fetchTagFiles(string $tag, ?string $token) : array{
	$ref = rawurlencode($tag);
	$data = githubGet("https://api.github.com/repos/pmmp/BedrockProtocol/git/ref/tags/$ref", $token);
	$sha = $data['object']['sha'];
	if($data['object']['type'] === 'tag'){
		$obj = githubGet($data['object']['url'], $token);
		$sha = $obj['object']['sha'];
	}
	$tree = githubGet("https://api.github.com/repos/pmmp/BedrockProtocol/git/trees/$sha?recursive=1", $token);
	$files = [];
	foreach($tree['tree'] as $item){
		if($item['type'] !== 'blob') continue;
		if(str_ends_with($item['path'], '.php') && str_starts_with($item['path'], 'src/')){
			$files[$item['path']] = $item['url'];
		}
	}
	return $files;
}

function fetchRawFile(string $blobUrl, ?string $token) : string{
	$blob = githubGet($blobUrl, $token);
	return base64_decode(str_replace("\n", '', $blob['content'] ?? ''), true);
}

$cacheDir = __DIR__ . '/.schema-cache';
if(!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

function diskCacheGet(string $key) : ?string{
	global $cacheDir;
	$path = $cacheDir . '/' . md5($key);
	return file_exists($path) ? file_get_contents($path) : null;
}

function diskCacheSet(string $key, string $value) : void{
	global $cacheDir;
	file_put_contents($cacheDir . '/' . md5($key), $value);
}

function cachedTree(string $tag, ?string $token) : array{
	$cached = diskCacheGet("tree:$tag");
	if($cached !== null) return json_decode($cached, true);
	$files = fetchTagFiles($tag, $token);
	diskCacheSet("tree:$tag", json_encode($files));
	return $files;
}

function cachedFile(string $blobUrl, string $tag, string $path, ?string $token) : string{
	$key = "file:$tag:$path";
	$cached = diskCacheGet($key);
	if($cached !== null) return $cached;
	$content = fetchRawFile($blobUrl, $token);
	diskCacheSet($key, $content);
	return $content;
}

$phpParser = (new ParserFactory())->createForNewestSupportedVersion();
$nodeFinder = new NodeFinder();

function classNameToProtocolInfoConst(string $className) : string{
	$base = preg_replace('/Packet$/', '', $className);
	$snake = preg_replace('/([a-z])([A-Z])/', '$1_$2', $base);
	$snake = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $snake);
	$snake = preg_replace('/([A-Z])([A-Z])/', '$1_$2', $snake);
	return strtoupper($snake) . '_PACKET';
}

function parseProtocolInfo(string $code) : array{
	global $phpParser, $nodeFinder;
	try{ $ast = $phpParser->parse($code); }catch(Throwable $e){ throw new RuntimeException("Failed to parse ProtocolInfo.php: " . $e->getMessage()); }
	$constants = [];
	foreach($nodeFinder->findInstanceOf($ast, Node\Stmt\ClassConst::class) as $classConst){
		foreach($classConst->consts as $const){
			$name = $const->name->toString();
			$value = $const->value;
			if($value instanceof Node\Scalar\LNumber){ $constants[$name] = $value->value; }
		}
	}
	return $constants;
}

function parsePacket(string $code) : array{
	global $phpParser, $nodeFinder;

	try{
		$ast = $phpParser->parse($code);
	}catch(Throwable){
		return ['className' => null, 'packetConst' => null, 'fields' => []];
	}

	$useMap = buildUseMap($ast);
	$className = null;
	$packetConst = null;
	$classes = $nodeFinder->findInstanceOf($ast, Node\Stmt\Class_::class);

	if(!empty($classes)){
		$class = $classes[0];
		$className = $class->name?->toString();

		foreach($class->stmts as $stmt){
			if(!$stmt instanceof Node\Stmt\ClassConst) continue;
			foreach($stmt->consts as $const){
				if($const->name->toString() !== 'NETWORK_ID') continue;
				$value = $const->value;
				if($value instanceof Node\Expr\ClassConstFetch
					&& $value->class instanceof Node\Name
					&& $value->name instanceof Node\Identifier
				){
					$packetConst = $value->name->toString();
				}
			}
		}
	}

	$fields = [];

	foreach($nodeFinder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method){
		if(!in_array($method->name->toString(), ['decodePayload', 'decode', 'read'], true)){
			continue;
		}
		$fields = extractFields($method->stmts ?? [], $useMap, prefix: '', depth: 0);
		if(!empty($fields)){
			break;
		}
	}

	return ['className' => $className, 'packetConst' => $packetConst, 'fields' => $fields];
}

function detectArrayUnion(Node\Expr $expr, array $useMap, int $depth, array $visited = []) : array{
	if(!$expr instanceof Node\Expr\Match_) return [];
	$types = [];
	foreach($expr->arms as $arm){
		$value = $arm->body;
		if($value instanceof Node\Expr\StaticCall && $value->class instanceof Node\Name){
			$short = $value->class->getLast();
			$fqn = $useMap[$short] ?? null;
			if($fqn !== null){
				$fields = resolveCompositeType($fqn, $useMap, '', $depth + 1, $visited);
				if(!empty($fields)) $types = array_merge($types, $fields);
			}
		}
		if($value instanceof Node\Expr\New_ && $value->class instanceof Node\Name){
			$short = $value->class->getLast();
			$fqn = $useMap[$short] ?? null;
			if($fqn !== null){
				$fields = resolveCompositeType($fqn, $useMap, '', $depth + 1, $visited);
				if(!empty($fields)) $types = array_merge($types, $fields);
			}
		}
	}
	return $types;
}

function detectSwitchUnion(Node\Stmt\Switch_ $stmt, array $useMap, int $depth, array $visited = []) : ?array{
	$types = [];
	foreach($stmt->cases as $case){
		foreach($case->stmts as $s){
			if($s instanceof Node\Stmt\Expression && $s->expr instanceof Node\Expr\Assign){
				$expr = $s->expr->expr;
				if($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name){
					$short = $expr->class->getLast();
					$fqn = $useMap[$short] ?? null;
					if($fqn !== null){
						$fields = resolveCompositeType($fqn, $useMap, '', $depth + 1, $visited);
						if(!empty($fields)) $types = array_merge($types, $fields);
					}
				}
			}
		}
	}
	return $types ?: null;
}

function detectArrayAppend(array $stmts) : ?array{
	foreach($stmts as $stmt){
		if(!$stmt instanceof Node\Stmt\Expression) continue;
		$expr = $stmt->expr;
		if(!$expr instanceof Node\Expr\Assign) continue;
		$lhs = $expr->var;
		if(!$lhs instanceof Node\Expr\ArrayDimFetch) continue;
		if(!$lhs->var instanceof Node\Expr\PropertyFetch) continue;
		if(!$lhs->var->var instanceof Node\Expr\Variable) continue;
		if($lhs->var->var->name !== 'this') continue;
		if(!$lhs->var->name instanceof Node\Identifier) continue;
		$arrayName = $lhs->var->name->toString();
		if($lhs->dim === null || $lhs->dim instanceof Node\Expr || $lhs->var instanceof Node\Expr\PropertyFetch){
			return ['arrayName' => $arrayName, 'expr' => $expr->expr, 'mode' => 'append'];
		}
		return ['arrayName' => $arrayName, 'expr' => $expr->expr, 'key' => $lhs->dim, 'mode' => 'map'];
	}
	return null;
}

// Detects keyed local-var append in loop body: $localArr[$key] = value
// Pattern from Experiments::read(): $experiments[$experimentName] = $enabled;
function detectKeyedLocalArrayAppend(array $stmts, array $useMap) : ?array{
	foreach($stmts as $stmt){
		if(!$stmt instanceof Node\Stmt\Expression) continue;
		$expr = $stmt->expr;
		if(!$expr instanceof Node\Expr\Assign) continue;
		$lhs = $expr->var;
		if(!($lhs instanceof Node\Expr\ArrayDimFetch
			&& $lhs->dim !== null
			&& $lhs->var instanceof Node\Expr\Variable
			&& is_string($lhs->var->name)
		)) continue;
		$arrayName = $lhs->var->name;
		$keyType = inferType($lhs->dim) ?? 'string';
		$valueType = inferType($expr->expr) ?? 'bool';
		return ['arrayName' => $arrayName, 'keyType' => $keyType, 'valueType' => $valueType];
	}
	return null;
}

function inferTypeFromQualified(string $qualified) : ?string{
	static $map = [
		'LE::readFloat' => 'le:f32',
		'LE::readDouble' => 'le:f64',
		'CommonTypes::getBool' => 'bool',
		'CommonTypes::getString' => 'string',
		'CommonTypes::getVector2' => 'vector2',
		'CommonTypes::getVector3' => 'vector3',
		'Byte::readUnsigned' => 'u8',
	];
	return $map[$qualified] ?? null;
}

function inferOptionalCallable(Node\Expr $expr) : ?string{
	if($expr instanceof Node\Expr\StaticCall
		&& $expr->class instanceof Node\Name
		&& $expr->name instanceof Node\Identifier
	){
		$class = $expr->class->toString();
		$method = $expr->name->toString();
		return inferTypeFromQualified("$class::$method");
	}

	if($expr instanceof Node\Expr\ArrowFunction){
		return inferOptionalCallable($expr->expr);
	}

	if($expr instanceof Node\Expr\Closure){
		foreach($expr->stmts as $stmt){
			if($stmt instanceof Node\Stmt\Return_){
				return inferOptionalCallable($stmt->expr);
			}
		}
	}

	return null;
}

function detectCountVariable(Node\Expr\Assign $assign, array &$countVars) : void{
	$lhs = $assign->var;
	if(!$lhs instanceof Node\Expr\Variable || !is_string($lhs->name)) return;
	$type = inferType($assign->expr);
	if($type !== null){ $countVars[$lhs->name] = $type; return; }
	if($assign->expr instanceof Node\Expr\StaticCall){
		$name = $assign->expr->name->toString();
		if(str_contains($name, 'read')) $countVars[$lhs->name] = 'varint';
	}
}

function extractArrayFromLoop(Node $stmt, array $countVars, array $useMap, int $depth, array $visited = [], array $localVarTypes = []) : ?array{
	if(!$stmt instanceof Node\Stmt\While_) return null;
	$cond = $stmt->cond;
	$countVar = null;
	if($cond instanceof Node\Expr\BinaryOp\Greater
		&& $cond->left instanceof Node\Expr\PostDec
		&& $cond->left->var instanceof Node\Expr\Variable
	){
		$countVar = $cond->left->var->name;
	}elseif($cond instanceof Node\Expr\PostDec && $cond->var instanceof Node\Expr\Variable){
		$countVar = $cond->var->name;
	}
	if($countVar === null || !isset($countVars[$countVar])) return null;
	$append = detectArrayAppend($stmt->stmts);
	if($append === null) return null;
	$entryFields = resolveArrayEntryExpr($append['expr'], $useMap, $depth, $visited, $localVarTypes);
	return ['name' => $append['arrayName'], 'type' => 'array', 'countType' => $countVars[$countVar], 'entry' => $entryFields];
}

function extractArrayFromForLoop(Node\Stmt\For_ $stmt, array $countVars, array $useMap, int $depth, array $visited = [], array $localVarTypes = []) : ?array{
	if(empty($stmt->cond)) return null;
	$cond = $stmt->cond[0];
	if(!$cond instanceof Node\Expr\BinaryOp\Smaller) return null;
	if(!$cond->right instanceof Node\Expr\Variable) return null;
	$countVar = $cond->right->name;
	if(!isset($countVars[$countVar])) return null;
	$append = detectArrayAppend($stmt->stmts);
	if($append === null) return null;
	$union = detectArrayUnion($append['expr'], $useMap, $depth, $visited);
	if(!empty($union)){
		return ['name' => $append['arrayName'], 'type' => 'array', 'countType' => $countVars[$countVar], 'entry' => $union];
	}
	$entryFields = resolveArrayEntryExpr($append['expr'], $useMap, $depth, $visited, $localVarTypes);
	return ['name' => $append['arrayName'], 'type' => 'array', 'countType' => $countVars[$countVar], 'entry' => $entryFields];
}

function extractArrayFromForeachLoop(Node\Stmt\Foreach_ $stmt, array $useMap, int $depth, array $visited = [], array $localVarTypes = []) : ?array{
	$expr = $stmt->expr;
	if(!($expr instanceof Node\Expr\PropertyFetch
		&& $expr->var instanceof Node\Expr\Variable
		&& $expr->var->name === 'this')
	) return null;
	$arrayName = $expr->name->toString();
	$append = detectArrayAppend($stmt->stmts);
	if($append === null) return null;
	$entryFields = resolveArrayEntryExpr($append['expr'], $useMap, $depth, $visited, $localVarTypes);
	return ['name' => $arrayName, 'type' => 'array', 'entry' => $entryFields];
}

function detectUnionRead(Node\Expr $expr, array $useMap, int $depth, array $visited = []) : ?array{
	if(!$expr instanceof Node\Expr\Match_) return null;

	$fields = [];

	foreach($expr->arms as $arm){
		$value = $arm->body;
		$primitive = inferType($value);

		if($primitive !== null){
			$fields[] = ['name' => 'value', 'type' => $primitive];
			continue;
		}

		if($value instanceof Node\Expr\StaticCall && $value->class instanceof Node\Name){
			$short = $value->class->getLast();
			$fqn = $useMap[$short] ?? null;
			if($fqn !== null){
				$inner = resolveCompositeType($fqn, $useMap, '', $depth + 1, $visited);
				if(!empty($inner)){
					$fields = array_merge($fields, $inner);
					continue;
				}
				$fields[] = ['name' => camelToSnake($short), 'type' => fqnToTypeString($fqn)];
				continue;
			}
		}

		if($value instanceof Node\Expr\New_ && $value->class instanceof Node\Name){
			$short = $value->class->getLast();
			$fqn = $useMap[$short] ?? null;
			if($fqn !== null){
				$inner = resolveCompositeType($fqn, $useMap, '', $depth + 1, $visited);
				if(!empty($inner)){
					$fields = array_merge($fields, $inner);
					continue;
				}
				$fields[] = ['name' => camelToSnake($short), 'type' => fqnToTypeString($fqn)];
				continue;
			}
		}

		if($value instanceof Node\Expr\Match_){
			$innerUnion = detectUnionRead($value, $useMap, $depth, $visited);
			if($innerUnion !== null){
				$fields = array_merge($fields, $innerUnion);
			}
		}
	}

	if(empty($fields)) return null;

	$unique = [];
	$seen = [];
	foreach($fields as $f){
		$key = $f['name'] . ':' . $f['type'];
		if(isset($seen[$key])) continue;
		$seen[$key] = true;
		$unique[] = $f;
	}

	return $unique;
}

function buildUseMap(array $ast) : array{
	global $nodeFinder;
	$map = [];
	foreach($nodeFinder->findInstanceOf($ast, Node\Stmt\Use_::class) as $use){
		foreach($use->uses as $useUse){
			$fqn = $useUse->name->toString();
			$alias = $useUse->alias?->toString() ?? basename(str_replace('\\', '/', $fqn));
			$map[$alias] = $fqn;
		}
	}
	return $map;
}

function extractFields(array $stmts, array $useMap, string $prefix, int $depth, array &$countVars = [], ?string $optionalFlag = null) : array{
	$seen = [];
	$fields = [];

	$addField = function(array $f) use (&$seen, &$fields, $optionalFlag) : void{
		if($optionalFlag !== null) $f['optionalFlag'] = $optionalFlag;
		$key = $f['name'] . ':' . $f['type'];
		if(!isset($seen[$key])){ $seen[$key] = true; $fields[] = $f; }
	};

	$mergeInner = function(array $inner) use ($addField) : void{
		foreach($inner as $f) $addField($f);
	};

	$pendingInstanceCalls = [];

	foreach($stmts as $stmt){
		if($stmt instanceof Node\Stmt\Expression){
			$expr = $stmt->expr;

			if($expr instanceof Node\Expr\Assign
				&& $expr->var instanceof Node\Expr\PropertyFetch
				&& $expr->var->var instanceof Node\Expr\Variable
				&& $expr->var->var->name === 'this'
				&& $expr->expr instanceof Node\Expr\New_
				&& $expr->expr->class instanceof Node\Name
			){
				$propName = $expr->var->name->toString();
				$shortName = $expr->expr->class->getLast();
				$pendingInstanceCalls[$propName] = $shortName;
			}

			if($expr instanceof Node\Expr\MethodCall
				&& $expr->var instanceof Node\Expr\PropertyFetch
				&& $expr->var->var instanceof Node\Expr\Variable
				&& $expr->var->var->name === 'this'
				&& $expr->name instanceof Node\Identifier
				&& in_array($expr->name->toString(), ['decodePayload', 'decode', 'read'], true)
			){
				$propName = $expr->var->name->toString();
				if(isset($pendingInstanceCalls[$propName])){
					$shortName = $pendingInstanceCalls[$propName];
					$fqn = $useMap[$shortName] ?? null;
					$typeStr = $fqn !== null ? fqnToTypeString($fqn) : camelToSnake($shortName);
					$addField(['name' => $propName, 'type' => $typeStr]);
					unset($pendingInstanceCalls[$propName]);
				}
			}

			if($expr instanceof Node\Expr\Assign){
				detectCountVariable($expr, $countVars);
				foreach(extractFromAssign($expr, $useMap, $prefix, $depth) as $f) $addField($f);
			}
		}elseif($stmt instanceof Node\Stmt\While_){
			$array = extractArrayFromLoop($stmt, $countVars, $useMap, $depth);
			if($array !== null){ $fields[] = $array; continue; }
			$mergeInner(extractFields($stmt->stmts, $useMap, $prefix, $depth, $countVars, $optionalFlag));
		}elseif($stmt instanceof Node\Stmt\For_){
			foreach($stmt->init as $initExpr){
				if($initExpr instanceof Node\Expr\Assign) detectCountVariable($initExpr, $countVars);
			}
			$array = extractArrayFromForLoop($stmt, $countVars, $useMap, $depth);
			if($array !== null){ $fields[] = $array; continue; }
			$mergeInner(extractFields($stmt->stmts, $useMap, $prefix, $depth, $countVars, $optionalFlag));
		}elseif($stmt instanceof Node\Stmt\Foreach_){
			$array = extractArrayFromForeachLoop($stmt, $useMap, $depth);
			if($array !== null){ $fields[] = $array; continue; }
			$mergeInner(extractFields($stmt->stmts, $useMap, $prefix, $depth, $countVars, $optionalFlag));
		}elseif($stmt instanceof Node\Stmt\If_){
			$flag = null;
			if($stmt->cond instanceof Node\Expr\MethodCall
				&& $stmt->cond->name instanceof Node\Identifier
				&& $stmt->cond->name->toString() === 'get'
				&& isset($stmt->cond->args[0])
			){
				$arg = $stmt->cond->args[0]->value;
				if($arg instanceof Node\Expr\ClassConstFetch) $flag = $arg->name->toString();
			}
			$mergeInner(extractFields($stmt->stmts, $useMap, $prefix, $depth, $countVars, $flag ?? $optionalFlag));
			foreach($stmt->elseifs as $elseif){
				$mergeInner(extractFields($elseif->stmts, $useMap, $prefix, $depth, $countVars, $optionalFlag));
			}
			if($stmt->else !== null){
				$mergeInner(extractFields($stmt->else->stmts, $useMap, $prefix, $depth, $countVars, $optionalFlag));
			}
		}elseif($stmt instanceof Node\Stmt\Switch_){
			$union = detectSwitchUnion($stmt, $useMap, $depth);
			if($union !== null){ $mergeInner($union); continue; }
			foreach($stmt->cases as $case){
				$mergeInner(extractFields($case->stmts, $useMap, $prefix, $depth, $countVars, $optionalFlag));
			}
		}
	}

	return $fields;
}

function extractFromAssign(Node\Expr\Assign $assign, array $useMap, string $prefix, int $depth) : array{
	$lhs = $assign->var;
	$propName = null;
	$isAppend = false;

	if($lhs instanceof Node\Expr\PropertyFetch
		&& $lhs->var instanceof Node\Expr\Variable
		&& $lhs->var->name === 'this'
		&& $lhs->name instanceof Node\Identifier
	){
		$propName = $lhs->name->toString();
	}elseif($lhs instanceof Node\Expr\ArrayDimFetch
		&& $lhs->dim === null
		&& $lhs->var instanceof Node\Expr\PropertyFetch
		&& $lhs->var->var instanceof Node\Expr\Variable
		&& $lhs->var->var->name === 'this'
	){
		$propName = $lhs->var->name->toString();
		$isAppend = true;
	}

	if($propName === null) return [];

	$fullName = $prefix !== '' ? "$prefix.$propName" : $propName;
	$rhs = $assign->expr;

	if($isAppend) return [];

	$union = detectUnionRead($rhs, $useMap, $depth);
	if($union !== null) return $union;

	if($rhs instanceof Node\Expr\StaticCall
		&& $rhs->class instanceof Node\Name
		&& $rhs->class->getLast() === 'CommonTypes'
		&& $rhs->name instanceof Node\Identifier
		&& $rhs->name->toString() === 'readOptional'
		&& isset($rhs->args[1])
	){
		$innerArg = $rhs->args[1]->value;
		$arrayNode = detectOptionalArray($innerArg, $useMap, $depth);
		if($arrayNode !== null){
			return [['name' => $fullName, 'type' => 'optional', 'value' => $arrayNode]];
		}
		$primitive = inferOptionalCallable($innerArg) ?? inferType($innerArg);
		if($primitive !== null){
			return [['name' => $fullName, 'type' => 'optional', 'value' => $primitive]];
		}
		return [['name' => $fullName, 'type' => 'optional', 'value' => 'unknown']];
	}

	$primitiveType = inferType($rhs);
	if($primitiveType !== null){
		return [['name' => $fullName, 'type' => $primitiveType]];
	}

	if($rhs instanceof Node\Expr\New_
		&& $rhs->class instanceof Node\Name
		&& $rhs->class->getLast() === 'CacheableNbt'
	){
		return [['name' => $fullName, 'type' => 'cacheable_nbt']];
	}

	if($depth < MAX_COMPOSITE_DEPTH
		&& $rhs instanceof Node\Expr\StaticCall
		&& $rhs->class instanceof Node\Name
		&& $rhs->name instanceof Node\Identifier
		&& in_array($rhs->name->toString(), ['read', 'decode', 'readFrom', 'decodeFrom'], true)
	){
		$shortClassName = $rhs->class->getLast();
		$fqn = $useMap[$shortClassName] ?? null;
		if($fqn !== null){
			$innerFields = resolveCompositeType($fqn, $useMap, '', $depth + 1);
			if(!empty($innerFields)){
				return prefixFields($innerFields, $fullName);
			}
			return [['name' => $fullName, 'type' => fqnToTypeString($fqn)]];
		}
	}

	return [];
}

const MAX_COMPOSITE_DEPTH = 4;

function fqnToTypeString(string $fqn) : string{
	$parts = explode('\\', $fqn);
	return camelToSnake(end($parts));
}

function camelToSnake(string $name) : string{
	$snake = preg_replace('/([a-z])([A-Z])/', '$1_$2', $name);
	$snake = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $snake);
	$snake = preg_replace('/([A-Z])([A-Z])/', '$1_$2', $snake);
	return strtolower($snake);
}

function resolveCompositeType(string $fqn, array $parentUseMap, string $prefix, int $depth, array $visited = []) : array{
	global $compositeTypeCache, $compositeSourceCache, $debugPackets;

	if(isset($visited[$fqn])) return [];
	if($depth > MAX_COMPOSITE_DEPTH) return [];

	$shortName = basename(str_replace('\\', '/', $fqn));
	$isDebug = !empty($debugPackets);

	if(array_key_exists($fqn, $compositeTypeCache)){
		$cached = $compositeTypeCache[$fqn];
		if($cached === null) return [];
		return prefixFields($cached, $prefix);
	}

	$visited[$fqn] = true;
	$filePath = fqnToPath($fqn);

	if(isset($compositeSourceCache[$filePath])){
		$code = $compositeSourceCache[$filePath];
	}else{
		$code = findFileInTreeCache($filePath);
		if($code === null){
			$altPath = 'src/' . $shortName . '.php';
			if($altPath !== $filePath){
				$code = findFileInTreeCache($altPath);
			}
		}
		$compositeSourceCache[$filePath] = $code;
	}

	if($code === null){
		$compositeTypeCache[$fqn] = null;
		return [];
	}

	$fields = parseCompositeTypeCode($code, $fqn, $depth, $visited);

	if(empty($fields)){
		$compositeTypeCache[$fqn] = null;
		return [];
	}

	$compositeTypeCache[$fqn] = $fields;
	return prefixFields($fields, $prefix);
}

function prefixFields(array $fields, string $prefix) : array{
	if($prefix === '') return $fields;
	return array_map(function(array $f) use ($prefix) : array{
		$f['name'] = $prefix . '.' . $f['name'];
		return $f;
	}, $fields);
}

function fqnToPath(string $fqn) : string{
	$prefixes = [
		'pocketmine\\network\\mcpe\\protocol\\',
		'pmmp\\encoding\\',
	];
	foreach($prefixes as $p){
		if(str_starts_with($fqn, $p)){
			return 'src/' . str_replace('\\', '/', substr($fqn, strlen($p))) . '.php';
		}
	}
	$parts = explode('\\', $fqn);
	if(count($parts) > 2){
		$relative = implode('/', array_slice($parts, 2));
		return 'src/' . $relative . '.php';
	}
	return 'src/' . end($parts) . '.php';
}

function findFileInTreeCache(string $filePath) : ?string{
	global $globalFileIndex, $fileSourceCache, $githubToken;
	if(isset($fileSourceCache[$filePath])) return $fileSourceCache[$filePath];
	if(!isset($globalFileIndex[$filePath])) return null;
	$code = fetchRawFile($globalFileIndex[$filePath], $githubToken);
	$fileSourceCache[$filePath] = $code;
	return $code;
}

function buildGlobalFileIndex(array $treesByProtocol) : array{
	$index = [];
	foreach($treesByProtocol as $tree){
		foreach($tree as $path => $blob){
			if(!isset($index[$path])) $index[$path] = $blob;
		}
	}
	return $index;
}

function parseCompositeTypeCode(string $code, string $fqn, int $depth, array $visited = []) : array{
	global $phpParser, $nodeFinder, $debugPackets;
	try{ $ast = $phpParser->parse($code); }catch(Throwable){ return []; }
	$useMap = buildUseMap($ast);
	$shortName = basename(str_replace('\\', '/', $fqn));
	$isDebug = !empty($debugPackets);
	$methods = $nodeFinder->findInstanceOf($ast, Node\Stmt\ClassMethod::class);

	foreach($methods as $method){
		$methodName = $method->name->toString();
		if(!in_array($methodName, ['read', 'decode', 'readFrom', 'decodeFrom'], true)) continue;
		if(!($method->flags & Node\Stmt\Class_::MODIFIER_STATIC)) continue;

		$hasReader = false;
		foreach($method->params as $param){
			if($param->type === null){
				if($param->var instanceof Node\Expr\Variable && is_string($param->var->name)
					&& str_contains($param->var->name, 'in')
				){ $hasReader = true; break; }
			}elseif($param->type instanceof Node\Name
				&& in_array($param->type->getLast(), ['ByteBufferReader', 'PacketSerializer', 'BinaryStream'], true)
			){ $hasReader = true; break; }
			elseif($param->type instanceof Node\UnionType){
				foreach($param->type->types as $t){
					if($t instanceof Node\Name && in_array($t->getLast(), ['ByteBufferReader', 'PacketSerializer', 'BinaryStream'], true)){
						$hasReader = true; break 2;
					}
				}
			}
		}

		if(!$hasReader) continue;

		$fields = extractCompositeFields($method->stmts ?? [], $useMap, $depth, $visited);
		if($isDebug){
			$summary = implode(', ', array_map(fn($f) => $f['name'] . ':' . $f['type'], $fields));
			echo "  [COMPOSITE] $shortName::$methodName() → " . count($fields) . " fields [$summary]\n";
		}
		if(!empty($fields)) return $fields;
	}

	// Fallback: non-static read methods (e.g. constructor-style classes)
	foreach($methods as $method){
		$methodName = $method->name->toString();
		if(!in_array($methodName, ['read', 'decode', 'readFrom', 'decodeFrom'], true)) continue;
		if($method->flags & Node\Stmt\Class_::MODIFIER_STATIC) continue;

		$fields = extractCompositeFields($method->stmts ?? [], $useMap, $depth, $visited);
		if($isDebug){
			$summary = implode(', ', array_map(fn($f) => $f['name'] . ':' . $f['type'], $fields));
			echo "  [COMPOSITE] $shortName::$methodName() (non-static fallback) → " . count($fields) . " fields [$summary]\n";
		}
		if(!empty($fields)) return $fields;
	}

	if($isDebug) echo "  [COMPOSITE] $shortName → NO FIELDS EXTRACTED\n";
	return [];
}

function detectOptionalArray(Node\Expr $expr, array $useMap, int $depth, array $visited = [], array $localVarTypes = []) : ?array{
	if(!($expr instanceof Node\Expr\ArrowFunction || $expr instanceof Node\Expr\Closure)){
		return null;
	}

	if($expr instanceof Node\Expr\ArrowFunction){
		return null;
	}

	$stmts = $expr->stmts;
	$countType = null;
	$entryExpr = null;

	foreach($stmts as $stmt){
		if($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\Assign){
			$primitive = inferType($stmt->expr->expr);
			if($primitive !== null){
				$countType = $primitive;
			}
		}

		if($stmt instanceof Node\Stmt\For_){
			foreach($stmt->stmts as $loopStmt){
				if($loopStmt instanceof Node\Stmt\Expression
					&& $loopStmt->expr instanceof Node\Expr\Assign
				){
					$lhs = $loopStmt->expr->var;
					if($lhs instanceof Node\Expr\ArrayDimFetch && $lhs->dim === null){
						$entryExpr = $loopStmt->expr->expr;
					}
				}
			}
		}
	}

	if($entryExpr === null) return null;

	$entryFields = resolveArrayEntryExpr($entryExpr, $useMap, $depth, $visited, $localVarTypes);

	return [
		'type' => 'array',
		'countType' => $countType ?? 'uvarint',
		'entry' => $entryFields,
	];
}

// Extracts fields from a static read() method in a composite type class.
// Unlike extractFields (which handles $this->prop assignments in decodePayload),
// composite types use local variable assignments ($name = ...).
function extractCompositeFields(array $stmts, array $useMap, int $depth, array $visited = [], array &$localVarTypes = []) : array{
	$fields = [];
	$seen = [];
	$countVars = [];
	$arrayInits = [];
	$localVarTypes = [];

	$addField = function(array $f) use (&$seen, &$fields) : void{
		$key = $f['name'] . ':' . ($f['type'] ?? '');
		if(!isset($seen[$key])){ $seen[$key] = true; $fields[] = $f; }
	};

	foreach($stmts as $stmt){
		if($stmt instanceof Node\Stmt\For_){
			foreach($stmt->init as $initExpr){
				if($initExpr instanceof Node\Expr\Assign){
					detectCountVariableLocal($initExpr, $countVars);
				}
			}
			$array = extractArrayFromForLoopLocal($stmt, $countVars, $useMap, $depth, $visited, $localVarTypes);
			if($array !== null){ $addField($array); continue; }
			foreach($stmt->stmts as $s){
				foreach(extractCompositeFields([$s], $useMap, $depth, $visited, $localVarTypes) as $f){
					$addField($f);
				}
			}
			continue;
		}

		if($stmt instanceof Node\Stmt\While_){
			$array = extractArrayFromLoopLocal($stmt, $countVars, $useMap, $depth, $visited);
			if($array !== null){ $addField($array); continue; }
			foreach($stmt->stmts as $s){
				foreach(extractCompositeFields([$s], $useMap, $depth, $visited, $localVarTypes) as $f){
					$addField($f);
				}
			}
			continue;
		}

		if(!($stmt instanceof Node\Stmt\Expression)) continue;

		$expr = $stmt->expr;

		if($expr instanceof Node\Expr\Assign){
			detectCountVariableLocal($expr, $countVars);
		}

		if(!($expr instanceof Node\Expr\Assign)) continue;

		$lhs = $expr->var;
		$rhs = $expr->expr;

		if($lhs instanceof Node\Expr\Variable && is_string($lhs->name)){
			$varName = $lhs->name;

			if(in_array($varName, ['i', 'j', 'k', 'len', 'count', 'n', 'idx', 'index', 'tmp', 'result', 'size', 'in', 'out'], true)){
				continue;
			}

			if($rhs instanceof Node\Expr\Array_ && empty($rhs->items)){
				$arrayInits[$varName] = true;
				continue;
			}

			if($rhs instanceof Node\Expr\New_
				&& $rhs->class instanceof Node\Name
				&& $rhs->class->getLast() === 'CacheableNbt'
			){
				$addField(['name' => $varName, 'type' => 'cacheable_nbt']);
				continue;
			}

			if($rhs instanceof Node\Expr\StaticCall
				&& $rhs->class instanceof Node\Name
				&& $rhs->class->getLast() === 'CommonTypes'
				&& $rhs->name instanceof Node\Identifier
				&& $rhs->name->toString() === 'readOptional'
				&& isset($rhs->args[1])
			){
				$innerArg = $rhs->args[1]->value;
				$arrayNode = detectOptionalArray($innerArg, $useMap, $depth, $visited, $localVarTypes);
				if($arrayNode !== null){
					$addField(['name' => $varName, 'type' => 'optional', 'value' => $arrayNode]);
					continue;
				}

				$primitive = inferOptionalCallable($innerArg) ?? inferType($innerArg);
				if($primitive !== null){
					$addField(['name' => $varName, 'type' => 'optional', 'value' => $primitive]);
					continue;
				}

				$innerExpr = null;
				if($innerArg instanceof Node\Expr\ArrowFunction){
					$innerExpr = $innerArg->expr;
				}elseif($innerArg instanceof Node\Expr\Closure){
					foreach($innerArg->stmts as $s){
						if($s instanceof Node\Stmt\Return_){ $innerExpr = $s->expr; break; }
					}
				}

				if($innerExpr instanceof Node\Expr\StaticCall
					&& $innerExpr->class instanceof Node\Name
					&& $innerExpr->name instanceof Node\Identifier
					&& in_array($innerExpr->name->toString(), ['read', 'decode', 'readFrom', 'decodeFrom'], true)
				){
					$shortName = $innerExpr->class->getLast();
					$fqn = $useMap[$shortName] ?? null;
					if($fqn !== null && $depth < MAX_COMPOSITE_DEPTH){
						$innerFields = resolveCompositeType($fqn, $useMap, '', $depth + 1, $visited);
						if(!empty($innerFields)){
							$addField(['name' => $varName, 'type' => 'optional', 'value' => ['type' => 'object', 'value' => fqnToTypeString($fqn)]]);
							continue;
						}
					}
					$typeString = $fqn !== null ? fqnToTypeString($fqn) : camelToSnake($shortName);
					$addField(['name' => $varName, 'type' => 'optional', 'value' => ['type' => 'object', 'value' => $typeString]]);
					continue;
				}

				$addField(['name' => $varName, 'type' => 'optional', 'value' => 'unknown']);
				continue;
			}

			$primitiveType = inferType($rhs);
			if($primitiveType !== null){
				$localVarTypes[$varName] = $primitiveType;
				$addField(['name' => $varName, 'type' => $primitiveType]);
				continue;
			}

			if($rhs instanceof Node\Expr\StaticCall
				&& $rhs->class instanceof Node\Name
				&& $rhs->name instanceof Node\Identifier
				&& in_array($rhs->name->toString(), ['read', 'decode', 'readFrom', 'decodeFrom'], true)
			){
				$shortName = $rhs->class->getLast();
				$fqn = $useMap[$shortName] ?? null;
				if($fqn !== null && $depth < MAX_COMPOSITE_DEPTH){
					$innerFields = resolveCompositeType($fqn, $useMap, '', $depth + 1, $visited);
					if(!empty($innerFields)){
						foreach(prefixFields($innerFields, $varName) as $f) $addField($f);
						continue;
					}
				}
				$typeString = $fqn !== null ? fqnToTypeString($fqn) : camelToSnake($shortName);
				$localVarTypes[$varName] = $typeString;
				$addField(['name' => $varName, 'type' => $typeString]);
				continue;
			}
		}

		if($lhs instanceof Node\Expr\ArrayDimFetch
			&& $lhs->dim === null
			&& $lhs->var instanceof Node\Expr\Variable
			&& is_string($lhs->var->name)
		){
			$varName = $lhs->var->name;
			if(isset($arrayInits[$varName]) && !isset($seen[$varName . ':array'])){
				$entryFields = resolveArrayEntryExpr($rhs, $useMap, $depth, $visited, $localVarTypes);
				$seen[$varName . ':array'] = true;
				$fields[] = ['name' => $varName, 'type' => 'array', 'entry' => $entryFields];
			}
		}
	}

	return $fields;
}

function detectCountVariableLocal(Node\Expr\Assign $assign, array &$countVars) : void{
	$lhs = $assign->var;
	if(!$lhs instanceof Node\Expr\Variable || !is_string($lhs->name)) return;
	$type = inferType($assign->expr);
	if($type !== null){ $countVars[$lhs->name] = $type; return; }
	if($assign->expr instanceof Node\Expr\StaticCall
		&& $assign->expr->name instanceof Node\Identifier
		&& str_contains($assign->expr->name->toString(), 'read')
	){
		$countVars[$lhs->name] = 'uvarint';
	}
}

// Detects array loop in composite type For context.
//
// Pattern A — entry fields from SomeClass::read():
//   for($i=0, $size=VarInt::read($in); $i<$size; $i++){
//     $array[] = SomeClass::read($in);
//   }
//
// Pattern B — entry fields from scalar reads before array append:
//   for($i=0, $count=LE::readUnsignedInt($in); $i<$count; $i++){
//     $name    = CommonTypes::getString($in);
//     $enabled = CommonTypes::getBool($in);
//     $experiments[] = new Experiment($name, $enabled);
//   }
function extractArrayFromForLoopLocal(
	Node\Stmt\For_ $stmt,
	array $countVars,
	array $useMap,
	int $depth,
	array $visited = [],
	array $localVarTypes = []
) : ?array{
	if(empty($stmt->cond)) return null;
	$cond = $stmt->cond[0];
	if(!$cond instanceof Node\Expr\BinaryOp\Smaller) return null;
	if(!$cond->right instanceof Node\Expr\Variable) return null;
	$countVar = $cond->right->name;
	if(!isset($countVars[$countVar])) return null;

	$arrayName = null;
	$entryExpr = null;
	$keyedAppend = null;

	foreach($stmt->stmts as $s){
		if(!$s instanceof Node\Stmt\Expression) continue;
		$e = $s->expr;
		if($e instanceof Node\Expr\Assign
			&& $e->var instanceof Node\Expr\ArrayDimFetch
			&& $e->var->var instanceof Node\Expr\Variable
			&& is_string($e->var->var->name)
		){
			if($e->var->dim === null){
				$arrayName = $e->var->var->name;
				$entryExpr = $e->expr;
			}else{
				$keyedAppend = ['arrayName' => $e->var->var->name, 'keyExpr' => $e->var->dim, 'valueExpr' => $e->expr];
			}
			break;
		}
	}

	// Keyed array pattern (e.g. Experiments): $experiments[$experimentName] = $enabled
	if($arrayName === null && $keyedAppend !== null){
		$bodyVarTypes = [];
		foreach($stmt->stmts as $s){
			if(!$s instanceof Node\Stmt\Expression) continue;
			$e = $s->expr;
			if($e instanceof Node\Expr\Assign
				&& $e->var instanceof Node\Expr\Variable
				&& is_string($e->var->name)
			){
				$t = inferType($e->expr);
				if($t !== null) $bodyVarTypes[$e->var->name] = $t;
			}
		}
		$keyExpr = $keyedAppend['keyExpr'];
		$keyName = ($keyExpr instanceof Node\Expr\Variable && is_string($keyExpr->name)) ? $keyExpr->name : 'key';
		$keyType = inferType($keyExpr) ?? ($bodyVarTypes[$keyName] ?? 'string');
		$valExpr = $keyedAppend['valueExpr'];
		$valName = ($valExpr instanceof Node\Expr\Variable && is_string($valExpr->name)) ? $valExpr->name : 'value';
		$valType = inferType($valExpr) ?? ($bodyVarTypes[$valName] ?? 'bool');
		return [
			'name' => $keyedAppend['arrayName'],
			'type' => 'array',
			'countType' => $countVars[$countVar],
			'entry' => [
				['name' => $keyName, 'type' => $keyType],
				['name' => $valName, 'type' => $valType],
			],
		];
	}

	if($arrayName === null) return null;

	$entryFields = null;

	if($entryExpr !== null){
		$entryFields = resolveArrayEntryExpr($entryExpr, $useMap, $depth, $visited, $localVarTypes);
	}

	// Pattern B: collect scalar/composite reads from loop body
	if($entryFields === null){
		$bodyFields = [];
		$bodySeen = [];
		foreach($stmt->stmts as $s){
			if(!$s instanceof Node\Stmt\Expression) continue;
			$e = $s->expr;
			if($e instanceof Node\Expr\Assign
				&& $e->var instanceof Node\Expr\ArrayDimFetch
				&& $e->var->dim === null
			) continue;
			if($e instanceof Node\Expr\Assign
				&& $e->var instanceof Node\Expr\Variable
				&& is_string($e->var->name)
			){
				$varName = $e->var->name;
				if(in_array($varName, ['i', 'j', 'k', 'n', 'idx', 'index', 'tmp', 'size', 'count'], true)) continue;

				if($e->expr instanceof Node\Expr\New_
					&& $e->expr->class instanceof Node\Name
					&& $e->expr->class->getLast() === 'CacheableNbt'
				){
					if(!isset($bodySeen[$varName])){ $bodySeen[$varName] = true; $bodyFields[] = ['name' => $varName, 'type' => 'cacheable_nbt']; }
					continue;
				}

				$primType = inferType($e->expr);
				if($primType !== null){
					if(!isset($bodySeen[$varName])){ $bodySeen[$varName] = true; $bodyFields[] = ['name' => $varName, 'type' => $primType]; }
					continue;
				}

				if($e->expr instanceof Node\Expr\StaticCall
					&& $e->expr->class instanceof Node\Name
					&& $e->expr->name instanceof Node\Identifier
					&& in_array($e->expr->name->toString(), ['read', 'decode', 'readFrom', 'decodeFrom'], true)
					&& strtolower($e->expr->class->getLast()) !== 'self'
					&& strtolower($e->expr->class->getLast()) !== 'static'
				){
					$shortName = $e->expr->class->getLast();
					$fqn = $useMap[$shortName] ?? null;
					if($fqn !== null && $depth < MAX_COMPOSITE_DEPTH){
						$inner = resolveCompositeType($fqn, $useMap, $varName, $depth + 1, $visited);
						if(!empty($inner)){
							foreach($inner as $f){ if(!isset($bodySeen[$f['name']])){ $bodySeen[$f['name']] = true; $bodyFields[] = $f; } }
							continue;
						}
					}
					$typeStr = $fqn !== null ? fqnToTypeString($fqn) : camelToSnake($shortName);
					if(!isset($bodySeen[$varName])){ $bodySeen[$varName] = true; $bodyFields[] = ['name' => $varName, 'type' => $typeStr]; }
				}
			}
		}
		if(!empty($bodyFields)) $entryFields = $bodyFields;
	}

	// Fallback: opaque type from entry expression
	if(empty($entryFields) && $entryExpr !== null){
		if($entryExpr instanceof Node\Expr\StaticCall && $entryExpr->class instanceof Node\Name){
			$sn = $entryExpr->class->getLast();
			$fqn = $useMap[$sn] ?? null;
			$entryFields = [['name' => 'value', 'type' => $fqn !== null ? fqnToTypeString($fqn) : camelToSnake($sn)]];
		}elseif($entryExpr instanceof Node\Expr\New_ && $entryExpr->class instanceof Node\Name){
			$sn = $entryExpr->class->getLast();
			if($sn !== 'CacheableNbt'){
				$fqn = $useMap[$sn] ?? null;
				$entryFields = [['name' => 'value', 'type' => $fqn !== null ? fqnToTypeString($fqn) : camelToSnake($sn)]];
			}
		}
	}

	return [
		'name' => $arrayName,
		'type' => 'array',
		'countType' => $countVars[$countVar],
		'entry' => $entryFields ?? [],
	];
}

function extractArrayFromLoopLocal(
	Node\Stmt\While_ $stmt,
	array $countVars,
	array $useMap,
	int $depth,
	array $visited = [],
	array $localVarTypes = []
) : ?array{
	$cond = $stmt->cond;
	$countVar = null;

	if($cond instanceof Node\Expr\BinaryOp\Greater
		&& $cond->left instanceof Node\Expr\PostDec
		&& $cond->left->var instanceof Node\Expr\Variable
	){
		$countVar = $cond->left->var->name;
	}elseif($cond instanceof Node\Expr\PostDec && $cond->var instanceof Node\Expr\Variable){
		$countVar = $cond->var->name;
	}

	if($countVar === null || !isset($countVars[$countVar])) return null;

	$arrayName = null;
	$entryExpr = null;

	foreach($stmt->stmts as $s){
		if(!$s instanceof Node\Stmt\Expression) continue;
		$e = $s->expr;
		if($e instanceof Node\Expr\Assign
			&& $e->var instanceof Node\Expr\ArrayDimFetch
			&& $e->var->dim === null
			&& $e->var->var instanceof Node\Expr\Variable
			&& is_string($e->var->var->name)
		){
			$arrayName = $e->var->var->name;
			$entryExpr = $e->expr;
			break;
		}
	}

	if($arrayName === null) return null;

	$entryFields = null;

	if($entryExpr !== null){
		$entryFields = resolveArrayEntryExpr($entryExpr, $useMap, $depth, $visited, $localVarTypes);
	}

	// Pattern B: collect scalar reads before append
	if($entryFields === null || empty($entryFields)){
		$bodyFields = [];
		$bodySeen = [];
		foreach($stmt->stmts as $s){
			if(!$s instanceof Node\Stmt\Expression) continue;
			$e = $s->expr;
			if($e instanceof Node\Expr\Assign
				&& $e->var instanceof Node\Expr\ArrayDimFetch
				&& $e->var->dim === null
			) continue;
			if($e instanceof Node\Expr\Assign
				&& $e->var instanceof Node\Expr\Variable
				&& is_string($e->var->name)
			){
				$varName = $e->var->name;
				if(in_array($varName, ['i', 'j', 'k', 'n', 'idx', 'index', 'size', 'count'], true)) continue;

				$primType = inferType($e->expr);
				if($primType !== null){
					if(!isset($bodySeen[$varName])){ $bodySeen[$varName] = true; $bodyFields[] = ['name' => $varName, 'type' => $primType]; }
					continue;
				}

				if($e->expr instanceof Node\Expr\StaticCall
					&& $e->expr->class instanceof Node\Name
					&& $e->expr->name instanceof Node\Identifier
					&& in_array($e->expr->name->toString(), ['read', 'decode', 'readFrom', 'decodeFrom'], true)
				){
					$shortName = $e->expr->class->getLast();
					$fqn = $useMap[$shortName] ?? null;
					$typeStr = $fqn !== null ? fqnToTypeString($fqn) : camelToSnake($shortName);
					if(!isset($bodySeen[$varName])){ $bodySeen[$varName] = true; $bodyFields[] = ['name' => $varName, 'type' => $typeStr]; }
				}
			}
		}
		if(!empty($bodyFields)) $entryFields = $bodyFields;
	}

	// Fallback: opaque type
	if(empty($entryFields) && $entryExpr !== null){
		if($entryExpr instanceof Node\Expr\StaticCall && $entryExpr->class instanceof Node\Name){
			$short = $entryExpr->class->getLast();
			$fqn = $useMap[$short] ?? null;
			$entryFields = [['name' => 'value', 'type' => 'object', 'value' => $fqn !== null ? fqnToTypeString($fqn) : camelToSnake($short)]];
		}elseif($entryExpr instanceof Node\Expr\New_ && $entryExpr->class instanceof Node\Name){
			$short = $entryExpr->class->getLast();
			if($short !== 'CacheableNbt'){
				$fqn = $useMap[$short] ?? null;
				$entryFields = [['name' => 'value', 'type' => 'object', 'value' => $fqn !== null ? fqnToTypeString($fqn) : camelToSnake($short)]];
			}
		}
	}

	return [
		'name' => $arrayName,
		'type' => 'array',
		'countType' => $countVars[$countVar],
		'entry' => $entryFields ?? [],
	];
}

function resolveArrayEntryExpr(Node\Expr $entryExpr, array $useMap, int $depth, array $visited = [], array $localVarTypes = []) : array{
	if($entryExpr instanceof Node\Expr\Variable){
		$var = $entryExpr->name;
		if(isset($localVarTypes[$var])){
			return [['name' => 'value', 'type' => 'object', 'value' => $localVarTypes[$var]]];
		}
		return [['name' => $var, 'type' => 'object', 'value' => camelToSnake($var)]];
	}

	if($entryExpr instanceof Node\Expr\MethodCall){
		$method = $entryExpr->name instanceof Node\Identifier ? $entryExpr->name->toString() : 'object';
		return [['name' => 'value', 'type' => 'object', 'value' => camelToSnake($method)]];
	}

	$union = detectArrayUnion($entryExpr, $useMap, $depth, $visited);
	if(!empty($union)) return $union;

	$primitive = inferType($entryExpr);
	if($primitive !== null) return [['name' => 'value', 'type' => $primitive]];

	if($entryExpr instanceof Node\Expr\ArrowFunction){
		return resolveArrayEntryExpr($entryExpr->expr, $useMap, $depth, $visited, $localVarTypes);
	}

	if($entryExpr instanceof Node\Expr\Closure){
		foreach($entryExpr->stmts as $stmt){
			if($stmt instanceof Node\Stmt\Return_ && $stmt->expr !== null){
				return resolveArrayEntryExpr($stmt->expr, $useMap, $depth, $visited, $localVarTypes);
			}
		}
	}

	if($entryExpr instanceof Node\Expr\StaticCall
		&& $entryExpr->class instanceof Node\Name
		&& $entryExpr->name instanceof Node\Identifier
		&& in_array($entryExpr->name->toString(), ['read', 'decode', 'readFrom', 'decodeFrom'], true)
	){
		$shortName = $entryExpr->class->getLast();
		$fqn = $useMap[$shortName] ?? null;
		if($fqn !== null && $depth < MAX_COMPOSITE_DEPTH){
			$innerFields = resolveCompositeType($fqn, $useMap, '', $depth + 1, $visited);
			if(!empty($innerFields)) return $innerFields;
		}
		if($fqn !== null){
			return [['name' => 'value', 'type' => 'object', 'value' => fqnToTypeString($fqn)]];
		}
	}

	if($entryExpr instanceof Node\Expr\New_ && $entryExpr->class instanceof Node\Name){
		$shortName = $entryExpr->class->getLast();
		if($shortName === 'CacheableNbt'){
			return [['name' => 'nbt', 'type' => 'cacheable_nbt']];
		}
		$fqn = $useMap[$shortName] ?? null;
		if($fqn !== null && $depth < MAX_COMPOSITE_DEPTH){
			$innerFields = resolveCompositeType($fqn, $useMap, '', $depth + 1, $visited);
			if(!empty($innerFields)) return $innerFields;
		}
		if($fqn !== null){
			return [['name' => 'value', 'type' => 'object', 'value' => fqnToTypeString($fqn)]];
		}
		return [['name' => 'value', 'type' => 'object', 'value' => camelToSnake($shortName)]];
	}

	return [];
}

function inferType(?Node\Expr $expr) : ?string{
	if($expr === null) return null;

	// Wrapper/factory pattern: delegates to first argument's primitive type.
	// e.g. Color::fromARGB(LE::readUnsignedInt($in)) → 'le:u32'
	//      SomeEnum::from(Byte::readUnsigned($in))   → 'u8'
	if($expr instanceof Node\Expr\StaticCall
		&& $expr->name instanceof Node\Identifier
		&& isset($expr->args[0])
	){
		$innerType = inferType($expr->args[0]->value);
		if($innerType !== null) return $innerType;
	}

	if(!($expr instanceof Node\Expr\MethodCall) && !($expr instanceof Node\Expr\StaticCall)) return null;

	$m = $expr->name instanceof Node\Identifier ? $expr->name->toString() : null;
	$class = null;

	if($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name) $class = $expr->class->toString();

	if($m === null) return null;

	if($class !== null){
		$qualifiedMap = [
			'Byte::readUnsigned' => 'u8',
			'Byte::readSigned' => 'i8',

			'VarInt::readUnsignedInt' => 'uvarint',
			'VarInt::readSignedInt' => 'varint',
			'VarInt::readUnsignedLong' => 'uvarlong',
			'VarInt::readSignedLong' => 'varlong',

			'LE::readShort' => 'le:i16',
			'LE::readUnsignedShort' => 'le:u16',
			'LE::readInt' => 'le:i32',
			'LE::readSignedInt' => 'le:i32',
			'LE::readUnsignedInt' => 'le:u32',
			'LE::readLong' => 'le:i64',
			'LE::readSignedLong' => 'le:i64',
			'LE::readUnsignedLong' => 'le:u64',
			'LE::readFloat' => 'le:f32',
			'LE::readDouble' => 'le:f64',

			'BE::readShort' => 'be:i16',
			'BE::readUnsignedShort' => 'be:u16',
			'BE::readInt' => 'be:i32',
			'BE::readSignedInt' => 'be:i32',
			'BE::readUnsignedInt' => 'be:u32',
			'BE::readLong' => 'be:i64',
			'BE::readSignedLong' => 'be:i64',
			'BE::readUnsignedLong' => 'be:u64',
			'BE::readFloat' => 'be:f32',
			'BE::readDouble' => 'be:f64',

			'CommonTypes::getString' => 'string',
			'CommonTypes::getBool' => 'bool',
			'CommonTypes::getUUID' => 'uuid',

			'CommonTypes::getVector3' => 'vector3',
			'CommonTypes::getVector2' => 'vector2',

			'CommonTypes::getBlockPosition' => 'blockpos',
			'CommonTypes::getSignedBlockPosition' => 'signed_blockpos',

			'CommonTypes::getActorRuntimeId' => 'actor_runtime_id',
			'CommonTypes::getActorUniqueId' => 'actor_unique_id',

			'CommonTypes::getEntityMetadata' => 'entity_metadata',
			'CommonTypes::getEntityLink' => 'entity_link',

			'CommonTypes::getItemStackWithoutStackId' => 'item_stack',
			'CommonTypes::getItemStackWrapper' => 'item_stack_wrapper',

			'CommonTypes::getRecipeIngredient' => 'recipe_ingredient',
			'CommonTypes::getGameRules' => 'game_rules',

			'CommonTypes::getStructureSettings' => 'structure_settings',
			'CommonTypes::getStructureEditorData' => 'structure_editor_data',

			'CommonTypes::getCommandOriginData' => 'command_origin_data',

			'CommonTypes::getSkin' => 'skin',

			'CommonTypes::getNbtRoot' => 'nbt_root',
			'CommonTypes::getNbtCompoundRoot' => 'nbt_compound',

			'CommonTypes::readRecipeNetId' => 'uvarint',
			'CommonTypes::readCreativeItemNetId' => 'uvarint',

			'CommonTypes::readItemStackNetIdVariant' => 'varint',
			'CommonTypes::readItemStackRequestId' => 'varint',
			'CommonTypes::readLegacyItemStackRequestId' => 'varint',
			'CommonTypes::readServerItemStackId' => 'varint',

			'CommonTypes::getRotationByte' => 'rotation_byte',
		];
		$qualified = "$class::$m";
		if(isset($qualifiedMap[$qualified])) return $qualifiedMap[$qualified];
	}

	return [
		'getUnsignedVarInt' => 'uvarint',
		'getVarInt' => 'varint',
		'getUnsignedVarLong' => 'uvarlong',
		'getVarLong' => 'varlong',
		'getByte' => 'u8',
		'getSignedByte' => 'i8',
		'getLShort' => 'le:i16',
		'getLUnsignedShort' => 'le:u16',
		'getLInt' => 'le:i32',
		'getLUnsignedInt' => 'le:u32',
		'getLLong' => 'le:i64',
		'getLFloat' => 'le:f32',
		'getLDouble' => 'le:f64',
		'getString' => 'string',
		'getBool' => 'bool',
		'getActorRuntimeId' => 'actor_runtime_id',
		'getActorUniqueId' => 'actor_unique_id',
		'getVector3' => 'vector3',
		'getVector2' => 'vector2',
		'getBlockPosition' => 'blockpos',
		'getSignedBlockPosition' => 'signed_blockpos',
		'getUUID' => 'uuid',
		'readActorRuntimeId' => 'actor_runtime_id',
		'readActorUniqueId' => 'actor_unique_id',
		'readVector3' => 'vector3',
		'readVector2' => 'vector2',
		'readBlockPosition' => 'blockpos',
		'readSignedBlockPosition' => 'signed_blockpos',
		'readUUID' => 'uuid',
		'readString' => 'string',
		'readBool' => 'bool',
	][$m] ?? null;
}

function defaultForType(string $type) : mixed{
	return match(true){
		$type === 'bool' => false,
		$type === 'string' => '',
		str_starts_with($type, 'le:f') => 0.0,
		default => 0,
	};
}

function mergeFieldsAcrossVersions(array $snapshotsByProtocol, string $packetName = '', array $customRules = [], string $parentPath = '') : array{
	$protocols = array_keys($snapshotsByProtocol);
	sort($protocols);
	if(empty($protocols)){
		return [];
	}

	$minProtocol = $protocols[0];
	$maxProtocol = end($protocols);

	$allFields = [];
	$entrySnapshots = [];

	foreach($snapshotsByProtocol as $protocol => $fields){
		foreach($fields as $f){
			$key = $f['name'] . ':' . $f['type'];
			if(!isset($allFields[$key])){
				$allFields[$key] = $f;
			}else{
				if(($f['type'] ?? null) === 'array' && isset($f['countType'])){
					$allFields[$key]['countType'] = $f['countType'];
				}
				if(isset($f['optionalFlag'])){
					$allFields[$key]['optionalFlag'] = $f['optionalFlag'];
				}
				if(isset($f['storeCountAs'])){
					$allFields[$key]['storeCountAs'] = $f['storeCountAs'];
				}
				if(isset($f['storeAs'])){
					$allFields[$key]['storeAs'] = $f['storeAs'];
				}
			}
			if(($f['type'] ?? null) === 'array' && isset($f['entry'])){
				$entrySnapshots[$key][$protocol] = $f['entry'];
			}
		}
	}

	foreach($allFields as $key => &$fieldDef){
		if(($fieldDef['type'] ?? null) !== 'array') continue;
		if(!isset($entrySnapshots[$key])) continue;

		$snapshots = $entrySnapshots[$key];
		foreach($protocols as $proto){
			if(!isset($snapshots[$proto])) $snapshots[$proto] = [];
		}

		$newPath = $parentPath === '' ? $fieldDef['name'] : $parentPath . '.' . $fieldDef['name'];
		$fieldDef['entry'] = mergeFieldsAcrossVersions($snapshots, $packetName, $customRules, $newPath);
	}
	unset($fieldDef);

	$result = [];

	foreach($allFields as $fieldDef){
		$name = $fieldDef['name'];
		$type = $fieldDef['type'];
		$fullPath = $parentPath === '' ? $name : $parentPath . '.' . $name;

		$customRule = $customRules[$packetName][$fullPath] ?? $customRules[$packetName][$name] ?? null;

		if($customRule !== null && is_array($customRule)){
			foreach($customRule as $rule){
				$entry = array_merge($fieldDef, $rule);
				$entry['name'] = $fieldDef['name'];

				if(isset($rule['type']) && $rule['type'] !== 'array'){
					unset($entry['countType']);
					unset($entry['entry']);
				}

				if(isset($rule['storeCountAs'])){
					$entry['storeCountAs'] = $rule['storeCountAs'];
				}
				if(isset($rule['storeAs'])){
					$entry['storeAs'] = $rule['storeAs'];
				}

				if(isset($rule['since'])){
					$entry['since'] = $rule['since'];
					$entry['default'] ??= defaultForType($entry['type']);
				}
				if(isset($rule['until'])){
					$entry['until'] = $rule['until'];
					$entry['default'] ??= defaultForType($entry['type']);
				}
				if(isset($fieldDef['optionalFlag'])){
					$entry['flag'] = $fieldDef['optionalFlag'];
				}
				$result[] = $entry;
			}
			continue;
		}

		if(($fieldDef['type'] ?? null) === 'array'){
			$ranges = [];
			$rangeStart = null;
			$prevProto = null;

			foreach($protocols as $proto){
				$activeHere = false;
				foreach($snapshotsByProtocol[$proto] as $f){
					if($f['name'] === $name && $f['type'] === $type){
						$activeHere = true;
						break;
					}
				}
				if($activeHere && $rangeStart === null){
					$rangeStart = $proto;
				}elseif(!$activeHere && $rangeStart !== null){
					$ranges[] = [$rangeStart, $prevProto];
					$rangeStart = null;
				}
				$prevProto = $proto;
			}

			if($rangeStart !== null) $ranges[] = [$rangeStart, $prevProto];

			foreach($ranges as [$since, $until]){
				$entry = $fieldDef;
				if($since > $minProtocol){
					$entry['since'] = $since;
					$entry['default'] = defaultForType($type);
				}
				if($until < $maxProtocol){
					$entry['until'] = $until;
					$entry['default'] ??= defaultForType($type);
				}
				if(isset($fieldDef['optionalFlag'])){
					$entry['flag'] = $fieldDef['optionalFlag'];
				}

				if(isset($fieldDef['storeCountAs'])){
					$entry['storeCountAs'] = $fieldDef['storeCountAs'];
				}
				if(isset($fieldDef['storeAs'])){
					$entry['storeAs'] = $fieldDef['storeAs'];
				}
				$result[] = $entry;
			}
			continue;
		}

		// non-array
		$ranges = [];
		$rangeStart = null;
		$prevProto = null;

		foreach($protocols as $proto){
			$activeHere = false;
			foreach($snapshotsByProtocol[$proto] as $f){
				if($f['name'] === $name && $f['type'] === $type){ $activeHere = true; break; }
			}
			if($activeHere && $rangeStart === null){
				$rangeStart = $proto;
			}elseif(!$activeHere && $rangeStart !== null){
				$ranges[] = [$rangeStart, $prevProto];
				$rangeStart = null;
			}
			$prevProto = $proto;
		}

		if($rangeStart !== null) $ranges[] = [$rangeStart, $prevProto];

		foreach($ranges as [$since, $until]){
			$entry = $fieldDef;
			if($since > $minProtocol){
				$entry['since'] = $since;
				$entry['default'] = defaultForType($type);
			}
			if($until < $maxProtocol){
				$entry['until'] = $until;
				$entry['default'] ??= defaultForType($type);
			}
			if(isset($fieldDef['optionalFlag'])){
				$entry['flag'] = $fieldDef['optionalFlag'];
			}
			$result[] = $entry;
		}
	}

	usort($result, function($a, $b) use ($snapshotsByProtocol, $protocols){
		return firstPos($a['name'], $snapshotsByProtocol, $protocols)
			<=> firstPos($b['name'], $snapshotsByProtocol, $protocols);
	});

	return $result;
}

function firstPos(string $name, array $snapshotsByProtocol, array $protocols) : int{
	foreach($protocols as $proto){
		foreach($snapshotsByProtocol[$proto] as $i => $f){
			if($f['name'] === $name) return $i;
		}
	}
	return PHP_INT_MAX;
}

function inferOptionalCallableType(Node\Expr $expr) : ?string{
	if($expr instanceof Node\Expr\ArrowFunction){
		return inferType($expr->expr);
	}

	if($expr instanceof Node\Expr\Closure){
		foreach($expr->stmts as $stmt){
			if($stmt instanceof Node\Stmt\Return_ && $stmt->expr !== null){
				return inferType($stmt->expr);
			}
		}
		return null;
	}

	if($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier){
		return inferType($expr);
	}

	if($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier){
		return inferType($expr);
	}

	return null;
}

function renderField(array $f, int $indent) : array{
	$pad = str_repeat("\t", $indent);
	$lines = [];

	$lines[] = "{$pad}'name' => '{$f['name']}',";
	$lines[] = "{$pad}'type' => '{$f['type']}',";

	switch($f['type']){
		case 'array':
			if(isset($f['countType'])){
				$lines[] = "{$pad}'countType' => '{$f['countType']}',";
			}
			if(isset($f['storeCountAs'])){
				$lines[] = "{$pad}'storeCountAs' => '{$f['storeCountAs']}',";
			}
			if(isset($f['storeAs'])){
				$lines[] = "{$pad}'storeAs' => '{$f['storeAs']}',";
			}
			$lines[] = "{$pad}'entry' => " . exportArray($f['entry'] ?? [], $indent) . ",";
			break;

		case 'optional':
			if(isset($f['value'])){
				if(is_array($f['value'])){
					$lines[] = "{$pad}'value' => " . exportArray($f['value'], $indent) . ",";
				}else{
					$lines[] = "{$pad}'value' => '{$f['value']}',";
				}
			}
			break;

		case 'cacheable_nbt':
			break;
	}

	if(isset($f['flag']))    $lines[] = "{$pad}'flag' => '{$f['flag']}',";
	if(isset($f['since']))   $lines[] = "{$pad}'since' => {$f['since']},";
	if(isset($f['until']))   $lines[] = "{$pad}'until' => {$f['until']},";
	if(array_key_exists('default', $f)) $lines[] = "{$pad}'default' => " . var_export($f['default'], true) . ",";

	return $lines;
}

function generateSchemasPhp(array $schemas, array $protocols, array $tags) : string{
	$lines = ['<?php', '', 'namespace Shared\\Nicholass003\\LittleBrother;', ''];
	$lines[] = '/**';
	$lines[] = ' * Auto-generated file. DO NOT EDIT MANUALLY.';
	$lines[] = ' * Generated by generate-schema.php';
	$lines[] = ' *';
	$lines[] = ' * Protocol versions (oldest → newest/current):';
	foreach($protocols as $i => $p) $lines[] = " *   $p → {$tags[$i]}";
	$lines[] = ' *';
	$lines[] = ' * ROOT-LEVEL keys per packet:';
	$lines[] = ' *   since=X    → new packet, absent in protocols < X. SchemaTranslator drops outbound.';
	$lines[] = ' *   manual=true → handled by ManualPacketHandler, SchemaTranslator skips.';
	$lines[] = ' *';
	$lines[] = ' * FIELD-LEVEL keys:';
	$lines[] = ' * since=X          → field added at protocol X, present until CURRENT';
	$lines[] = ' * until=X          → field present until protocol X, absent in CURRENT';
	$lines[] = ' * since=X, until=Y → field exists only between X and Y';
	$lines[] = ' * (neither)        → field present in all protocols (passthrough)';
	$lines[] = ' * type=array       → array field; countType=length reader type;';
	$lines[] = ' *                    entry=per-element fields (may have since/until)';
	$lines[] = ' * type=cacheable_nbt → opaque NBT bytes, passed through as-is';
	$lines[] = ' * type=optional      → bool prefix + optional payload, opaque';
	$lines[] = ' */';
	$lines[] = '';
	$lines[] = 'class Schemas{';
	$lines[] = "\tpublic static function getSchemas() : array{";
	$lines[] = "\t\treturn [";

	foreach($schemas as $packetId => $schema){
		if(empty($schema['fields']) && empty($schema['manual'])) continue;
		$lines[] = "\t\t\t$packetId => [";
		$lines[] = "\t\t\t\t'packet' => '{$schema['packet']}',";
		if(isset($schema['since']))   $lines[] = "\t\t\t\t'since' => {$schema['since']},";
		if(!empty($schema['manual'])) $lines[] = "\t\t\t\t'manual' => true,";
		$lines[] = "\t\t\t\t'fields' => [";
		foreach($schema['fields'] as $i => $f){
			$lines[] = "\t\t\t\t\t$i => [";
			foreach(renderField($f, 6) as $line) $lines[] = $line;
			$lines[] = "\t\t\t\t\t],";
		}
		$lines[] = "\t\t\t\t],";
		$lines[] = "\t\t\t],";
	}

	$lines[] = "\t\t];";
	$lines[] = "\t}";
	$lines[] = '}';
	return implode("\n", $lines) . "\n";
}

function exportArray(array $data, int $indent = 0) : string{
	$pad = str_repeat("\t", $indent);
	$padInner = str_repeat("\t", $indent + 1);
	$lines = "[\n";

	foreach($data as $k => $v){
		if(is_int($k)){
			$lines .= $padInner . exportArray($v, $indent + 1) . ",\n";
			continue;
		}
		if(is_array($v)){
			$lines .= $padInner . "'$k' => " . exportArray($v, $indent + 1) . ",\n";
		}elseif(is_string($v)){
			$lines .= $padInner . "'$k' => '$v',\n";
		}elseif(is_bool($v)){
			$lines .= $padInner . "'$k' => " . ($v ? 'true' : 'false') . ",\n";
		}else{
			$lines .= $padInner . "'$k' => $v,\n";
		}
	}

	$lines .= $pad . "]";
	return $lines;
}

// Main

if(file_exists(DEBUG_LOG_FILE)) unlink(DEBUG_LOG_FILE);

$compositeTypeCache = [];
$fileSourceCache = [];
$compositeSourceCache = [];

$totalSteps = count($protocols) + 3 + (!empty($mojangPatches) ? 1 : 0);
$step = 1;

echo "[{$step}/{$totalSteps}] Fetching file trees from GitHub...\n";
$treesByProtocol = [];
foreach($protocols as $i => $protocol){
	// Skip fetch for protocols sourced from Mojang patch (tree would duplicate the previous tag)
	if(isset($mojangPatches[(string) $protocol])){
		echo "  [protocol $protocol] skipped (Mojang patch)\n";
		debugLog("TREE_FETCH", "skip | protocol=$protocol | Mojang patch");
		$treesByProtocol[$protocol] = [];
		continue;
	}
	$tag = $tags[$i];
	echo "  [$tag] ";
	try{
		$treesByProtocol[$protocol] = cachedTree($tag, $githubToken);
		$fromCache = diskCacheGet("tree:$tag") !== null;
		echo count($treesByProtocol[$protocol]) . " files" . ($fromCache ? " (cached)" : "") . "\n";
	}catch(Throwable $e){
		echo "FAILED: " . $e->getMessage() . "\n";
		exit(1);
	}
}
$globalFileIndex = buildGlobalFileIndex($treesByProtocol);
$step++;

echo "\n[{$step}/{$totalSteps}] Parsing ProtocolInfo.php for packet ID mapping...\n";
$packetIdMap = [];
foreach($protocols as $i => $protocol){
	if(isset($mojangPatches[(string) $protocol])){
		debugLog("PROTOCOL_INFO", "skip | protocol=$protocol | Mojang patch");
		continue;
	}
	$tag = $tags[$i];
	$tree = $treesByProtocol[$protocol];
	$protocolInfoPath = null;
	foreach(array_keys($tree) as $path){
		if(basename($path) === 'ProtocolInfo.php'){ $protocolInfoPath = $path; break; }
	}
	if($protocolInfoPath === null){ echo "  WARNING: ProtocolInfo.php not found in $tag\n"; continue; }
	try{
		$code = cachedFile($tree[$protocolInfoPath], $tag, $protocolInfoPath, $githubToken);
		$constants = parseProtocolInfo($code);
		$packetConsts = array_filter($constants, fn(string $k) => str_ends_with($k, '_PACKET'), ARRAY_FILTER_USE_KEY);
		$packetIdMap = array_merge($packetIdMap, $packetConsts);
		echo "  [$tag] " . count($packetConsts) . " packet IDs\n";
	}catch(Throwable $e){
		echo "  WARNING: Failed for $tag: " . $e->getMessage() . "\n";
	}
}
if(empty($packetIdMap)){ echo "ERROR: No packet IDs loaded.\n"; exit(1); }
echo "  Total unique: " . count($packetIdMap) . "\n";
$step++;

echo "\n[{$step}/{$totalSteps}] Parsing packet files...\n";
$parsedByProtocol = [];
foreach($protocols as $i => $protocol){
	if(isset($mojangPatches[(string) $protocol])){
		$parsedByProtocol[$protocol] = [];
		echo "  Protocol $protocol: skipped (Mojang patch)\n";
		debugLog("PARSE_PACKETS", "skip | protocol=$protocol | Mojang patch");
		continue;
	}
	$tag = $tags[$i];
	$tree = $treesByProtocol[$protocol];
	$count = 0;
	echo "  Protocol $protocol ($tag): ";
	$parsedByProtocol[$protocol] = [];
	foreach($tree as $path => $blobUrl){
		if(!str_ends_with($path, 'Packet.php') || str_contains($path, 'test')) continue;
		try{
			$code = cachedFile($blobUrl, $tag, $path, $githubToken);
			$parsed = parsePacket($code);
			if(isset(MANUAL_PACKETS[$parsed['className'] ?? ''])){
				debugLog("MANUAL_PACKET", "$tag | {$parsed['className']}");
				$parsed['fields'] = [];
			}
			if($parsed['className'] === null){
				debugLog("PARSE_FAIL", "$tag | $path | className not detected");
				continue;
			}
			if($parsed['packetConst'] === null){
				debugLog("NO_NETWORK_ID", "$tag | {$parsed['className']}");
			}
			if(empty($parsed['fields'])){
				debugLog("NO_FIELDS", "$tag | {$parsed['className']}");
			}else{
				debugLog("PARSED", "$tag | {$parsed['className']} | fields=" . count($parsed['fields']));
			}
			$parsedByProtocol[$protocol][$path] = $parsed;
			$count++;
		}catch(Throwable $e){
			debugLog("PARSE_EXCEPTION", "$tag | $path | " . $e->getMessage());
		}
	}
	echo "$count packets parsed\n";
}
$step++;

if(!empty($mojangPatches)){
	echo "\n[{$step}/{$totalSteps}] Applying Mojang protocol patches...\n";
	debugLog("MOJANG_PATCH", "applying " . count($mojangPatches) . " patches");
	foreach($mojangPatches as $protoStr => $changelogFile){
		$patchProtocol = (int) $protoStr;

		if(!in_array($patchProtocol, $protocols, true)){
			echo "  WARNING: protocol $patchProtocol in mojang_patches but not in versions config, skip\n";
			debugLog("MOJANG_PATCH", "skip | protocol=$patchProtocol | not in versions config");
			continue;
		}

		$alreadyParsed = isset($parsedByProtocol[$patchProtocol]) && !empty($parsedByProtocol[$patchProtocol]);
		if($alreadyParsed){
			echo "  Protocol $patchProtocol already available from pmmp/BedrockProtocol, skip Mojang patch\n";
			debugLog("MOJANG_PATCH", "skip | protocol=$patchProtocol | already parsed from pmmp");
			continue;
		}

		$parsedByProtocol[$patchProtocol] = buildMojangPatchSnapshot(
			$patchProtocol,
			$changelogFile,
			$parsedByProtocol,
			$protocols,
			$githubToken
		);
	}
	$step++;
}

echo "\n[{$step}/{$totalSteps}] Resolving packet IDs and merging fields...\n";
$allPaths = [];
foreach($parsedByProtocol as $parsed){
	foreach(array_keys($parsed) as $path) $allPaths[$path] = true;
}
$schemas = [];
$stats = ['resolved' => 0, 'unresolved' => 0, 'versioned' => 0, 'passthrough' => 0, 'new_packet' => 0, 'manual' => 0];
$unresolvedReport = [];

foreach(array_keys($allPaths) as $path){
	$packetName = basename($path, '.php');
	$className = null;
	$constName = null;
	$cerealId = null;
	$isMojangPatch = false;

	foreach($protocols as $protocol){
		if(!isset($parsedByProtocol[$protocol][$path])) continue;
		$entry = $parsedByProtocol[$protocol][$path];
		$className = $entry['className'] ?? null;
		$constName = $entry['packetConst'] ?? null;
		if(isset($entry['_cereal_id'])){
			$cerealId = $entry['_cereal_id'];
			$isMojangPatch = true;
		}
		if($constName !== null) break;
	}

	// Resolve packet ID:
	// 1. Via packetIdMap from pmmp ProtocolInfo (normal path)
	// 2. Fallback: cereal_id from Mojang JSON $metaProperties (patch path)
	$packetId = $packetIdMap[$constName] ?? null;

	if($packetId === null && $cerealId !== null){
		$packetId = $cerealId;
		debugLog("RESOLVE_CEREAL", "$packetName | constName=$constName | cereal_id=$cerealId");
	}

	if($packetId === null){
		debugLog("UNRESOLVED_PACKET_ID", "$packetName | const=$constName | cereal_id=null");
		$stats['unresolved']++;
		$unresolvedReport[] = "$packetName → tried constant: $constName, cereal_id: null";
		continue;
	}

	$stats['resolved']++;
	debugLog(
		$isMojangPatch ? "RESOLVE_MOJANG" : "RESOLVE_PMMP",
		"$packetName | packetId=$packetId" . ($isMojangPatch ? " | via cereal_id" : "")
	);

	// Find the first protocol where this packet appears
	$packetSince = null;
	foreach($protocols as $protocol){
		if(isset($parsedByProtocol[$protocol][$path])){
			if($protocol > $protocols[0]) $packetSince = $protocol;
			break;
		}
	}

	$isManual = isset(MANUAL_PACKETS[$className ?? $packetName]);

	if($isManual){
		$schemaEntry = ['packet' => $packetName, 'manual' => true, 'fields' => []];
		if($packetSince !== null) $schemaEntry['since'] = $packetSince;
		$schemas[$packetId] = $schemaEntry;
		$stats['manual']++;
		continue;
	}

	$snapshots = [];
	foreach($protocols as $protocol){
		$snapshots[$protocol] = $parsedByProtocol[$protocol][$path]['fields'] ?? [];
	}
	$merged = mergeFieldsAcrossVersions($snapshots, $packetName, FIELD_OVERRIDES);
	$isVersioned = !empty(array_filter($merged, fn($f) => isset($f['since']) || isset($f['until'])));

	if($packetSince !== null)     $stats['new_packet']++;
	elseif($isManual)             $stats['manual']++;
	elseif($isVersioned)          $stats['versioned']++;
	else                          $stats['passthrough']++;

	if(isset(PACKETS_OVERRIDES[$className ?? $packetName])){
		$merged = PACKETS_OVERRIDES[$className ?? $packetName];
	}

	$schemaEntry = ['packet' => $packetName, 'fields' => $merged];
	if($packetSince !== null) $schemaEntry['since'] = $packetSince;
	if($isManual)             $schemaEntry['manual'] = true;
	$schemas[$packetId] = $schemaEntry;
}
ksort($schemas);

echo "  Resolved:    {$stats['resolved']}\n";
echo "  Unresolved:  {$stats['unresolved']}\n";
echo "  New packet:  {$stats['new_packet']} (root-level since)\n";
echo "  Manual:      {$stats['manual']}\n";
echo "  Versioned:   {$stats['versioned']}\n";
echo "  Passthrough: {$stats['passthrough']}\n";
if(!empty($unresolvedReport)){
	echo "\n  Unresolved (manual check needed):\n";
	foreach($unresolvedReport as $line) echo "    $line\n";
}

$outDir = dirname($outFile);
if(!is_dir($outDir)) mkdir($outDir, 0755, true);
file_put_contents($outFile, generateSchemasPhp($schemas, $protocols, $tags));
echo "\nWritten: $outFile\n";

echo "\n=== Packets with Version Differences ===\n";
$packets = [];
foreach($schemas as $id => $schema){
	$packets[] = $schema['packet'];
	$versioned = array_filter($schema['fields'], fn($f) => isset($f['since']) || isset($f['until']));
	if(empty($versioned)) continue;
	echo "\n  0x" . str_pad(dechex($id), 3, '0', STR_PAD_LEFT) . " {$schema['packet']}\n";
	foreach($versioned as $f){
		$range = match(true){
			isset($f['since'], $f['until']) => "{$f['since']}–{$f['until']}",
			isset($f['since']) => "since {$f['since']} (→ current)",
			isset($f['until']) => "until {$f['until']} (gone in current)",
		};
		$marker = match(true){
			isset($f['since']) && !isset($f['until']) => '+',
			isset($f['until']) && !isset($f['since']) => '-',
			default => '~',
		};
		echo "    $marker {$f['name']} ({$f['type']}) [$range]\n";
	}
}
//var_dump($packets);
echo "\nDone! Total schemas: " . count($schemas) . "\n\n";
