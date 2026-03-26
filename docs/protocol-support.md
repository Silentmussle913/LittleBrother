# Protocol Support

This document describes the Minecraft Bedrock protocol versions currently supported by **LittleBrother**.

LittleBrother provides a packet translation layer that allows clients using different protocol versions to connect to servers running the native PocketMine-MP protocol.

> [!WARNING]
> Protocol translation support is **experimental**. Major internal changes may affect compatibility, stability, and behavior between releases.

---

## Current Server Protocol

| Protocol | Minecraft Version | Notes                         |
| -------- | ----------------- | ----------------------------- |
| 944      | 1.26.10           | Native PocketMine-MP protocol |

The native protocol corresponds to the version used by the running PocketMine-MP server.

---

## Experimental Translation Support

| Protocol | Minecraft Version | Status                   |
| -------- | ----------------- | ------------------------ |
| 924      | 1.26.0            | Partial support          |
| 898      | 1.21.130          | Partial support          |
| 860      | 1.21.120          | Supported (experimental) |
| 844      | 1.21.110          | Supported (experimental) |

Experimental protocols may not support all packets or gameplay features. Issues such as desync, visual glitches, or incomplete interactions may occur.

---

## Notes

* Translation is implemented using a **schema-driven packet translation system**.
* A **PacketContext** is used to propagate protocol, direction, and runtime state across the translation pipeline.
* Some packets require **manual handlers** where automatic schema translation is insufficient.
* Translation occurs at both **packet-level and batch-level**, depending on the handler implementation.
* Compatibility varies depending on gameplay features and protocol differences.

---

## Future Support

Additional protocol versions will be supported as the translation system matures.

The long-term goal of LittleBrother is to provide a **scalable, maintainable multi-version architecture** for Minecraft Bedrock protocol compatibility.

---

## Related Documentation

- Changelog:  
  [`changelogs/index.md`](../changelogs/index.md)

- Project Repository:  
  https://github.com/nicholass003/LittleBrother