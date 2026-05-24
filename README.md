<div align="center">

# 🧩 LittleBrother

### Bedrock Multi-Version Compatibility Layer for PocketMine-MP

A PocketMine-MP plugin that provides a **packet translation layer** allowing players using **older Minecraft Bedrock client versions** to connect to servers running newer protocol versions — without forcing anyone to update.

[![License](https://img.shields.io/github/license/nicholass003/LittleBrother)](LICENSE)
[![PocketMine API](https://img.shields.io/badge/PocketMine--MP%20API-5.43.0-blue)](https://github.com/pmmp/PocketMine-MP)
[![GitHub Release](https://img.shields.io/github/v/release/nicholass003/LittleBrother)](https://github.com/nicholass003/LittleBrother/releases)
[![GitHub Downloads](https://img.shields.io/github/downloads/nicholass003/LittleBrother/total)](https://github.com/nicholass003/LittleBrother/releases)

[![Poggit](https://poggit.pmmp.io/shield.state/LittleBrother)](https://poggit.pmmp.io/p/LittleBrother)
[![Poggit API](https://poggit.pmmp.io/shield.api/LittleBrother)](https://poggit.pmmp.io/p/LittleBrother)
[![Poggit Downloads](https://poggit.pmmp.io/shield.dl.total/LittleBrother)](https://poggit.pmmp.io/p/LittleBrother)

[![Discord](https://img.shields.io/discord/1230982180742631457?logo=discord&logoColor=white&color=5865F2)](https://discord.gg/EEJK2vxtCp)
[![GitHub Stars](https://img.shields.io/github/stars/nicholass003/LittleBrother)](https://github.com/nicholass003/LittleBrother/stargazers)

</div>

---

## ⚠️ Project Status

> **🚧 Active Alpha Development**
>
> LittleBrother is currently in active development and the core translation system is evolving rapidly.
>
> While many packet translators and runtime mappings are already implemented and functioning correctly, protocol translation support is still considered experimental and may vary depending on the protocol version, gameplay feature, or packet type.
>
> Stability and compatibility continue to improve between releases, but issues such as desync, incomplete packet handling, or runtime mapping inconsistencies may still occur.

---

## 🎯 Why LittleBrother?

One of the most frustrating problems in Minecraft Bedrock server hosting:

> **Players using older clients can't join servers running newer protocol versions.**

Instead of forcing players to update — or downgrading your server — LittleBrother introduces a **seamless translation layer** that bridges the gap between protocol versions automatically.

✅ No forced client updates  
✅ No server downgrades  
✅ Transparent to players  
✅ Clean, modular architecture

---

## 🧠 How It Works

LittleBrother sits silently between the client and the server, translating packets in both directions in real time using the new **Axiom runtime translation architecture**.

```text
Client (Old Protocol)
        │
        ▼
┌───────────────────┐
│  Runtime Inbound  │  ← Translates old → new
│    Translator     │
└───────────────────┘
        │
        ▼
PocketMine Server (Latest Protocol)
        │
        ▼
┌───────────────────┐
│ Runtime Outbound  │  ← Translates new → old
│    Translator     │
└───────────────────┘
        │
        ▼
Client (Old Protocol)
```

> Old clients send packets → LittleBrother translates them at runtime → Server processes normally → Responses are translated back into compatible packets. ✨

---

## 🏗️ Architecture Overview

LittleBrother is built around a modular runtime translation architecture designed to scale across multiple Bedrock protocol versions.

| Component                      | Description                                                                 |
| ------------------------------ | --------------------------------------------------------------------------- |
| 🔄 **Runtime Translator**      | Core runtime engine handling packet translation between protocol versions   |
| ⚡ **Axiom Runtime Codecs**     | Dynamic protocol-aware serialization and deserialization system             |
| 🛠️ **Manual Packet Handlers** | Handles complex packets where automatic runtime translation is insufficient |
| 🗺️ **Runtime Mapping System** | Handles block, item, and runtime ID remapping across versions               |

---

## 🛠️ Development Tools

LittleBrother ships with internal tools that assist protocol research, runtime mapping, and development workflows.

---

### 📥 `generate-protocol-data.php`

Downloads the required Bedrock protocol data from **pmmp/BedrockData**.

**Fetched data includes:**

* `canonical_block_states.nbt`
* `block_state_meta_map.json`
* `required_item_list.json`

Stored per protocol version inside:

```text
resources/data/bedrock/<protocol>/
```

**Usage:**

```bash
php tools/generate-protocol-data.php --config=schema-config.json
```

The script automatically fetches data from GitHub, caches results locally, and skips already-downloaded versions. ⚡

---

## 📦 Planned Features

* [x] 🌐 Multi-version Bedrock client compatibility
* [x] ⚡ Runtime-based packet translation
* [x] 🛠️ Manual packet handlers for complex packets
* [x] 🗺️ Runtime ID remapping system
* [x] 🔄 Bidirectional protocol translation
* [ ] 🌍 Broader legacy protocol support
* [ ] ⚙️ Improved gameplay synchronization across versions

---

## 📌 Important Notes

LittleBrother is a **full runtime packet translation system**, not just a handshake modifier.

The plugin handles:

* Packet structure differences across versions
* Runtime ID remapping
* Protocol-level serialization changes
* Dynamic packet translation between supported protocols

> ⚠️ Very large version gaps may still require additional manual handling and runtime adjustments.

---

## 💖 Support Development

If you find LittleBrother or my other PocketMine-MP plugins useful, your support means a lot! It helps me dedicate more time and resources to improving and expanding these tools for the community. 💙

### 💰 Financial Support

Donations are welcome via **[PayPal](https://paypal.me/FireRashkar)**. Your generosity helps cover hosting, development tools, and everything that keeps this project alive.

### 💻 Code Contributions

Pull requests are always welcome! If you're a developer who wants to help build the future of multi-version Bedrock compatibility, jump in.

### 📝 Feedback & Bug Reports

Your feedback shapes the direction of this project. Found a bug? Have a feature idea? [Open an issue](https://github.com/nicholass003/LittleBrother/issues) — every bit of feedback helps.

---

### 🙏 Acknowledgements

A heartfelt thank you to everyone who has supported this project — through code contributions, donations, or valuable feedback. You keep this project alive and thriving. 🚀

---

## 👤 Author

- [@nicholass003](https://github.com/nicholass003/)

---

<div align="center">

Made with ❤️ for the PocketMine-MP community

</div>