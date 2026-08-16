---
paths:
  - 'app/Filament/Pages/**'
---

# Pages

## Refresh a custom page infolist after state changes
To force a custom Page's infolist to re-render after mutating Livewire state, call `$this->cacheSchema('infolist', null)` (protected, from InteractsWithSchemas — two args with null unsets the cached schema). Do NOT poke `$cachedSchemas` / `$isCachingSchemas` directly; that internal hack breaks across Filament upgrades.
