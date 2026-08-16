---
paths:
  - app/Actions/ConsumeBatch.php
---

# Actions

## Parent-first lock order for batch quantity sync
Batch consumption/aggregation must lock the parent Item row first, then the item's batches (lockForUpdate), in that order, inside DB::transaction(..., attempts: 3). ConsumeBatch and Batch::updateItemQuantity() both follow this so concurrent consumption of different batches of one item can't race the denormalized Item.quantity. Never lock batches before the item.
