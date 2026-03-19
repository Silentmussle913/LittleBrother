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

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\BlockStateDictionary;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;

require __DIR__ . '/../vendor/autoload.php';

const LOG_FILE = __DIR__ . '/../build/log/block-upgrade-schema-debug.log';

function logMsg(string $message) : void{
	echo $message . "\n";
	$time = date("Y-m-d H:i:s");
	file_put_contents(
		LOG_FILE,
		"[$time] $message\n",
		FILE_APPEND
	);
}

$opts = getopt('', ['config:', 'token:']);

$configPath = $opts['config'] ?? 'config.json';

if(!file_exists($configPath)){
	echo "ERROR: config.json not found\n";
	exit(1);
}

$config = json_decode(file_get_contents($configPath), true);

if(!is_array($config)){
	echo "ERROR: invalid config\n";
	exit(1);
}

$outDir = rtrim($config['out_dir'] ?? "resources/data/block-upgrade-schema", "/");
$token = $opts['token'] ?? $config['token'] ?? null;

if(!is_dir($outDir)){
	mkdir($outDir, 0755, true);
}

$serializer = new NetworkNbtSerializer();

logMsg("\n=== Bedrock Block Upgrade Schema Generator ===\n\n");

foreach($config["versions"] as $entry){
	$schemaId = (int) $entry["schema"];

	$from = $entry["from"];
	$to = $entry["to"];
	$preview = $entry["preview"] ?? false;

	$fromProtocol = $entry["from_protocol"];
	$toProtocol = $entry["to_protocol"];

	logMsg("Processing schema $schemaId ($from -> $to)");

	$dataPath = "release";

	if($preview){
		$dataPath = "preview";
	}

	$oldUrl = "https://raw.githubusercontent.com/Kaooot/bedrock-network-data/master/$dataPath/$fromProtocol/block_palette.nbt";
	$newUrl = "https://raw.githubusercontent.com/Kaooot/bedrock-network-data/master/$dataPath/$toProtocol/block_palette.nbt";

	logMsg("Old palette URL: $oldUrl");
	logMsg("New palette URL: $newUrl");

	$oldTmp = tempnam(sys_get_temp_dir(), "palette_old_");
	$newTmp = tempnam(sys_get_temp_dir(), "palette_new_");

	githubFetch($oldUrl, $oldTmp, $token);
	githubFetch($newUrl, $newTmp, $token);

	$oldRaw = file_get_contents($oldTmp);
	$newRaw = file_get_contents($newTmp);

	logMsg("Old palette hash: " . md5($oldRaw));
	logMsg("New palette hash: " . md5($newRaw));

	$oldCanonical = convertPaletteToCanonical($oldRaw);
	$newCanonical = convertPaletteToCanonical($newRaw);

	$oldStates = loadPaletteStates($oldCanonical);
	$newStates = loadPaletteStates($newCanonical);

    analyzeStateDifferences($oldStates, $newStates);

	[$maj,$min,$patch,$rev] = extractVersionFromCanonical($newCanonical, $serializer);

	$renamed = detectRenamed($oldStates, $newStates);
	$added = detectAddedProperties($oldStates, $newStates);
	$renamedProps = detectRenamedProperties($oldStates, $newStates);
	$removedProps = detectRemovedProperties($oldStates, $newStates);
	$remappedValues = detectRemappedPropertyValues($oldStates, $newStates);
	$remappedIndex = buildRemappedPropertyValueIndex($remappedValues);
	$flatten = detectFlattenedProperties($oldStates, $newStates);

	logMsg("Renamed IDs: " . count($renamed));
	logMsg("Renamed Properties: " . count($renamedProps));
	logMsg("Added Properties: " . count($added));
	logMsg("Removed Properties: " . count($removedProps));
	logMsg("Remapped Property Values: " . count($remappedValues));
	logMsg("Flattened Properties: " . count($flatten));
	logMsg("State difference: " . (count($newStates) - count($oldStates)) . "\n");

	$schema = [
		"maxVersionMajor" => $maj,
		"maxVersionMinor" => $min,
		"maxVersionPatch" => $patch,
		"maxVersionRevision" => $rev
	];

	if($renamed) $schema["renamedIds"] = $renamed;
	if($renamedProps) $schema["renamedProperties"] = $renamedProps;
	if($added) $schema["addedProperties"] = $added;
	if($removedProps) $schema["removedProperties"] = $removedProps;

	if($remappedValues){
		$schema["remappedPropertyValues"] = $remappedValues;
		$schema["remappedPropertyValuesIndex"] = $remappedIndex;
	}

	if($flatten){
		$schema["flattenedProperties"] = $flatten;
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

	logMsg("Schema saved: $filename \n");
}

logMsg("Generation finished");
logMsg("Output directory: $outDir");
logMsg("Finished\n");

function githubFetch(string $url, string $target, ?string $token) : void{
	logMsg("Downloading: $url");

	$headers = [
		"User-Agent: BedrockSchemaGenerator"
	];

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

	logMsg("Downloaded " . strlen($data) . " bytes");

	file_put_contents($target, $data);
}

function analyzeStateDifferences(array $oldStates, array $newStates) : void{
    $oldMap = groupStatesByName($oldStates);
    $newMap = groupStatesByName($newStates);

    $addedBlocks = [];
    $removedBlocks = [];

    foreach($newMap as $name => $_){
        if(!isset($oldMap[$name])){
            $addedBlocks[] = $name;
        }
    }

    foreach($oldMap as $name => $_){
        if(!isset($newMap[$name])){
            $removedBlocks[] = $name;
        }
    }

    logMsg("Blocks added: " . count($addedBlocks));
    logMsg("Blocks removed: " . count($removedBlocks));

    foreach($addedBlocks as $b){
        logMsg("  + block: $b");
    }

    foreach($removedBlocks as $b){
        logMsg("  - block: $b");
    }

    $stateAdded = 0;
    $stateRemoved = 0;
    $stateChanged = 0;

    foreach($oldMap as $name => $oldList){

        if(!isset($newMap[$name])){
            continue;
        }

        $newList = $newMap[$name];

        $oldEncoded = [];
        foreach($oldList as $s){
            $oldEncoded[] = encodeStates($s->getStates());
        }

        $newEncoded = [];
        foreach($newList as $s){
            $newEncoded[] = encodeStates($s->getStates());
        }

        $oldEncoded = array_unique($oldEncoded);
        $newEncoded = array_unique($newEncoded);

        foreach($newEncoded as $state){
            if(!in_array($state, $oldEncoded, true)){
                $stateAdded++;
                logMsg("  + state in $name");
            }
        }

        foreach($oldEncoded as $state){
            if(!in_array($state, $newEncoded, true)){
                $stateRemoved++;
                logMsg("  - state in $name");
            }
        }

        if(count($oldEncoded) !== count($newEncoded)){
            $stateChanged++;
        }
    }

    logMsg("States added: $stateAdded");
    logMsg("States removed: $stateRemoved");
    logMsg("Blocks with state changes: $stateChanged");
}

function loadPalette(string $canonicalData, NetworkNbtSerializer $serializer) : array{
	$roots = $serializer->readMultiple($canonicalData);

	$blocks = [];

	foreach($roots as $root){
		$nbt = $root->mustGetCompoundTag();

		$name = $nbt->getString("name");

		$statesTag = $nbt->getCompoundTag("states");

		$states = [];

		foreach($statesTag->getValue() as $k => $v){
			$states[$k] = $v->getValue();
		}

		$blocks[$name][] = [
			"states" => $states,
			"version" => $nbt->getInt("version", 0)
		];
	}

	return $blocks;
}

function loadPaletteStates(string $canonical) : array{
	return BlockStateDictionary::loadPaletteFromString($canonical);
}

function convertPaletteToCanonical(string $paletteData) : string{
	$decompressed = ErrorToExceptionHandler::trapAndRemoveFalse(
		fn() => zlib_decode($paletteData)
	);

	$compound = (new BigEndianNbtSerializer())
		->read($decompressed)
		->mustGetCompoundTag();

	$states = [];

	/** @var CompoundTag $block */
	foreach($compound->getListTag("blocks") as $block){
		$block->removeTag("name_hash", "network_id", "block_id");

		$state = BlockStateData::fromNbt($block);

		$states[] = new TreeRoot(
			$state->toVanillaNbt()
		);
	}

	return (new NetworkNbtSerializer())->writeMultiple($states);
}

function encodeStates(array $states) : string{
	ksort($states, SORT_STRING);

	$tag = new CompoundTag();
	foreach($states as $k => $v){
		$tag->setTag($k, $v);
	}

	return (new LittleEndianNbtSerializer())->write(
		new TreeRoot($tag)
	);
}

function extractVersionFromCanonical(string $canonicalData, NetworkNbtSerializer $serializer) : array{
	$roots = $serializer->readMultiple($canonicalData);

	foreach($roots as $root){
		$tag = $root->mustGetCompoundTag();

		if($tag->getTag("version") !== null){

			$v = $tag->getInt("version");

			return [
				($v >> 24) & 0xff,
				($v >> 16) & 0xff,
				($v >> 8) & 0xff,
				$v & 0xff
			];
		}
	}

	return [1,0,0,0];
}

function buildStateSignature(array $states) : string{
    $encoded = [];

    foreach($states as $state){
        $encoded[] = encodeStates($state->getStates());
    }

    sort($encoded);

    return md5(implode("|", $encoded));
}

function groupStatesByName(array $states) : array{
	$map = [];

	foreach($states as $state){
		$map[$state->getName()][] = $state;
	}

	return $map;
}

function detectRenamed(array $oldStates, array $newStates) : array{

    $oldMap = groupStatesByName($oldStates);
    $newMap = groupStatesByName($newStates);

    $signatureMap = [];

    foreach($newMap as $name => $states){
        $sig = buildStateSignature($states);
        $signatureMap[$sig][] = $name;
    }

    $renamed = [];

    foreach($oldMap as $oldName => $oldList){

        if(isset($newMap[$oldName])){
            continue;
        }

        $sig = buildStateSignature($oldList);

        if(!isset($signatureMap[$sig])){
            continue;
        }

        $candidates = $signatureMap[$sig];

        $bestScore = 0;
        $best = null;

        foreach($candidates as $candidate){

            similar_text($oldName, $candidate, $score);

            if($score > $bestScore){
                $bestScore = $score;
                $best = $candidate;
            }
        }

        if($best !== null){
            $renamed[$oldName] = $best;
            logMsg("Detected rename: $oldName -> $best");
        }
    }

    return $renamed;
}

function detectAddedProperties(array $oldStates, array $newStates) : array{
	$result = [];

	$oldMap = groupStatesByName($oldStates);
	$newMap = groupStatesByName($newStates);

	foreach($newMap as $name => $newList){
		if(!isset($oldMap[$name])){
			continue;
		}

		$pairs = pairStates($oldMap[$name], $newList);

		foreach($pairs as [$oldState, $newState]){
			foreach($newState->getStates() as $k => $tag){
				if($oldState->getState($k) !== null){
					continue;
				}

				if(isset($result[$name][$k])){
					continue;
				}

				if($tag instanceof ByteTag){
					$result[$name][$k] = ["byte" => $tag->getValue()];
				}elseif($tag instanceof IntTag){
					$result[$name][$k] = ["int" => $tag->getValue()];
				}elseif($tag instanceof StringTag){
					$result[$name][$k] = ["string" => $tag->getValue()];
				}
			}
		}
	}

	return $result;
}

function detectRenamedProperties(array $oldStates, array $newStates) : array{
	$result = [];

	$oldMap = groupStatesByName($oldStates);
	$newMap = groupStatesByName($newStates);

	foreach($oldMap as $name => $oldList){
		if(!isset($newMap[$name])){
			continue;
		}

		$oldProps = [];
		foreach($oldList as $s){
			foreach($s->getStates() as $k => $v){
				$oldProps[$k] = true;
			}
		}

		$newProps = [];
		foreach($newMap[$name] as $s){
			foreach($s->getStates() as $k => $v){
				$newProps[$k] = true;
			}
		}

		foreach(array_keys($oldProps) as $oldProp){
			if(isset($newProps[$oldProp])){
				continue;
			}

			foreach(array_keys($newProps) as $newProp){
				if(isset($result[$name][$oldProp])){
					continue;
				}

				$result[$name][$oldProp] = $newProp;
			}
		}
	}

	return $result;
}

function detectRemovedProperties(array $oldStates, array $newStates) : array{
	$result = [];

	$oldMap = groupStatesByName($oldStates);
	$newMap = groupStatesByName($newStates);

	foreach($oldMap as $name => $oldList){
		if(!isset($newMap[$name])){
			continue;
		}

		$oldProps = [];
		foreach($oldList as $s){
			foreach($s->getStates() as $k => $v){
				$oldProps[$k] = true;
			}
		}

		$newProps = [];
		foreach($newMap[$name] as $s){
			foreach($s->getStates() as $k => $v){
				$newProps[$k] = true;
			}
		}

		foreach(array_keys($oldProps) as $prop){
			if(!isset($newProps[$prop])){
				$result[$name][] = $prop;
			}
		}
	}

	return $result;
}

function detectRemappedPropertyValues(array $oldStates, array $newStates) : array{
	$result = [];

	$oldMap = groupStatesByName($oldStates);
	$newMap = groupStatesByName($newStates);

	foreach($oldMap as $name => $oldList){
		if(!isset($newMap[$name])){
			continue;
		}

		$pairs = pairStates($oldList, $newMap[$name]);

		foreach($pairs as [$oldState, $newState]){
			foreach($oldState->getStates() as $prop => $oldTag){
				$newTag = $newState->getState($prop);

				if($newTag === null){
					continue;
				}

				if(!$oldTag->equals($newTag)){

					$oldVal = (string) $oldTag->getValue();
					$newVal = (string) $newTag->getValue();

					$result[$name][$prop][$oldVal] = $newVal;
				}
			}
		}
	}

	return $result;
}

function detectFlattenedProperties(array $oldStates, array $newStates) : array{
	$result = [];

	$oldMap = groupStatesByName($oldStates);
	$newMap = groupStatesByName($newStates);

	$newIds = array_keys($newMap);

	foreach($oldMap as $oldName => $oldList){
		$valuesByProp = [];

		foreach($oldList as $state){
			foreach($state->getStates() as $prop => $tag){
				$valuesByProp[$prop][(string) $tag->getValue()] = true;
			}
		}

		foreach($valuesByProp as $prop => $values){
			if(count($values) < 2){
				continue;
			}

			$prefix = null;
			$suffix = null;
			$valid = true;

			foreach(array_keys($values) as $value){
				$match = null;

				foreach($newIds as $newId){
					$escaped = preg_quote((string) $value,"/");

					if(preg_match("/^(.+?)" . $escaped . "(.+)$/",$newId,$m)){
						$match = [$m[1],$m[2]];
						break;
					}
				}

				if($match === null){
					$valid = false;
					break;
				}

				[$p,$s] = $match;

				if($prefix === null){
					$prefix = $p;
					$suffix = $s;
				}else{
					if($prefix !== $p || $suffix !== $s){
						$valid = false;
						break;
					}
				}
			}

			if($valid){
				$result[$oldName] = [
					"prefix" => $prefix,
					"flattenedProperty" => $prop,
					"suffix" => $suffix
				];
			}
		}
	}

	return $result;
}

function compareStates(BlockStateData $old, BlockStateData $new) : int{
	$score = 0;

	if($old->getName() === $new->getName()){
		$score += 10;
	}

	$oldStates = $old->getStates();
	$newStates = $new->getStates();

	foreach($oldStates as $prop => $tag){
		$newTag = $new->getState($prop);

		if($newTag === null){
			continue;
		}

		$score += 5;

		if($tag->equals($newTag)){
			$score += 10;
		}
	}

	return $score;
}

function pairStates(array $oldList, array $newList) : array{
	$pairs = [];

	$used = [];

	foreach($oldList as $oldState){
		$bestIndex = null;
		$bestScore = -1;

		foreach($newList as $i => $newState){
			if(isset($used[$i])){
				continue;
			}

			$score = compareStates($oldState,$newState);

			if($score > $bestScore){
				$bestScore = $score;
				$bestIndex = $i;
			}
		}

		if($bestIndex !== null){

			$pairs[] = [$oldState,$newList[$bestIndex]];

			$used[$bestIndex] = true;
		}
	}

	return $pairs;
}

function buildRemappedPropertyValueIndex(array &$remappedValues) : array{
	$index = [];
	$tableId = [];

	foreach($remappedValues as $block => &$props){
		foreach($props as $prop => $mapping){
			ksort($mapping);

			$signature = json_encode($mapping);

			if(!isset($tableId[$signature])){
				$id = $prop . "_" . str_pad((string) count($tableId),2,"0",STR_PAD_LEFT);

				$tableId[$signature] = $id;

				$index[$id] = [];

				foreach($mapping as $old => $new){
					if(is_numeric($old)){
						$oldTag = ["int" => (int) $old];
					}else{
						$oldTag = ["string" => $old];
					}

					if(is_numeric($new)){
						$newTag = ["int" => (int) $new];
					}else{
						$newTag = ["string" => $new];
					}

					$index[$id][] = [
						"old" => $oldTag,
						"new" => $newTag
					];
				}
			}

			$props[$prop] = $tableId[$signature];
		}
	}

	return $index;
}
