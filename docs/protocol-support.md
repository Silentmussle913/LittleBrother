# Protocol Support

This document describes the Minecraft Bedrock protocol versions currently supported by **LittleBrother**.

LittleBrother provides a packet translation layer that allows clients using older protocol versions to connect to servers running newer protocol versions.

> [!WARNING]
> Protocol translation support is currently **experimental** and may change between releases.

---

## Current Server Protocol

| Protocol | Minecraft Version | Notes |
|--------|------------------|------|
| 924 | 1.26.0 | Native PocketMine-MP protocol |

The native protocol corresponds to the protocol version used by the running PocketMine-MP server.

---

## Experimental Translation Support

| Protocol | Minecraft Version | Status |
|--------|------------------|--------|
| 898 | 1.21.130 | Translation in development |

Experimental protocols may not yet support all packets or gameplay features.

---

## Notes

- Protocol translation is implemented using **schema-based packet translation**.
- Some packets may still require **manual handlers** where automatic translation is not possible.
- Compatibility may vary depending on gameplay features and protocol differences.

---

## Future Support

Additional protocol versions may be supported as the translator implementation evolves.

The goal of LittleBrother is to provide a maintainable architecture capable of supporting multiple Minecraft Bedrock protocol versions.

---

## Related Documentation

- Changelog:  
  [`changelogs/index.md`](../changelogs/index.md)

- Project Repository:  
  https://github.com/nicholass003/LittleBrother