<div align="center">

# 🧩 LittleBrother

### Bedrock Multi-Version Compatibility Layer for PocketMine-MP

A PocketMine-MP plugin that provides a **packet translation layer** allowing players using **older Minecraft Bedrock client versions** to connect to servers running newer protocol versions — without forcing anyone to update.

[![State](https://img.shields.io/badge/state-early--development-orange)](https://github.com/nicholass003/LittleBrother) [![PocketMine](https://img.shields.io/badge/PocketMine-MP-blue)](https://github.com/pmmp/PocketMine-MP) [![Minecraft](https://img.shields.io/badge/Minecraft-Bedrock-green)](https://minecraft.net) [![License](https://img.shields.io/github/license/nicholass003/LittleBrother)](LICENSE)

</div>

---

## ⚠️ Project Status

> **🚧 Early Development**
>
> LittleBrother is in active early-stage development and is **not yet ready for production use**.
>
> The packet translation system, schema generator, and protocol compatibility layer are still under heavy development. Things may break or change at any time.

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

LittleBrother sits silently between the client and the server, translating packets in both directions in real time.

```
Client (Old Protocol)
        │
        ▼
┌───────────────────┐
│  Inbound Packet   │  ← Translates old → new
│    Translator     │
└───────────────────┘
        │
        ▼
PocketMine Server (Latest Protocol)
        │
        ▼
┌───────────────────┐
│  Outbound Packet  │  ← Translates new → old
│    Translator     │
└───────────────────┘
        │
        ▼
Client (Old Protocol)
```

> Old clients send packets → LittleBrother upgrades them → Server processes normally → LittleBrother downgrades the response → Old client receives compatible packets. ✨

---

## 🏗️ Architecture Overview

LittleBrother is built with a clean, modular translation architecture designed to scale across many protocol versions.

| Component | Description |
|---|---|
| 🔄 **Protocol Translator** | Core engine — handles packet translation between protocol versions |
| 📋 **Schema Translator** | Uses protocol schemas to automatically translate packet fields |
| 🛠️ **Manual Packet Handlers** | Handles edge-case packets too complex for schema-based translation |
| 🗂️ **Type Registry** | Defines binary serialization types used across all supported protocols |

---

## 🛠️ Development Tools

LittleBrother ships with powerful internal tools that automate large parts of the development workflow.

---

### 📥 `generate-protocol-data.php`

Downloads the required Bedrock protocol data from **pmmp/BedrockData**.

**Fetched data includes:**
- `canonical_block_states.nbt`
- `block_state_meta_map.json`
- `required_item_list.json`

Stored per protocol version inside:
```
resources/data/bedrock/<protocol>/
```

**Usage:**
```bash
php tools/generate-protocol-data.php --config=schema-config.json
```

The script automatically fetches data from GitHub, caches results locally, and skips already-downloaded versions. ⚡

---

### 📐 `generate-schema.php`

Automatically generates **packet schemas** by analyzing the source code of `pmmp/BedrockProtocol`.

**How it works:**
1. Downloads packet source code for each protocol version
2. Parses the PHP AST
3. Detects packet fields automatically
4. Merges field differences across versions
5. Produces a final schema file at `build/schemas.php`

**Usage:**
```bash
php tools/generate-schema.php --config=schema-config.json
```

**Features:**
- 🌲 AST-based packet field extraction
- 🔍 Automatic detection of field additions, removals, optional fields, arrays, and composite structures
- 🏷️ Automatic `since` / `until` version tagging
- 🔧 Manual packet override support

---

## 📦 Planned Features

- [ ] 🌐 Multi-version Bedrock client compatibility
- [ ] 📐 Automatic packet schema generation
- [ ] 🔄 Schema-based packet translation
- [ ] 🛠️ Manual packet handlers for complex packets
- [ ] ⚡ Efficient binary packet rewriting
- [ ] 🗺️ Protocol data remapping

---

## 📥 Installation *(Future)*

Once the plugin is production-ready:

1. Download `LittleBrother.phar`
2. Place it inside your `/plugins/` folder
3. Restart PocketMine-MP

> 📌 Watch this repo for updates on the first stable release!

---

## 📌 Important Notes

LittleBrother is a **full packet translation system**, not just a handshake modifier.

The plugin handles:
- Packet structure changes across versions
- Field additions and removals
- Protocol-level differences

> ⚠️ Very large version gaps may still require additional manual adjustments. We're working on minimizing this.

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