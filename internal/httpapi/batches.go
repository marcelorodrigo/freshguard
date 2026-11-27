package httpapi

import (
	"net/http"

	"github.com/marcelorodrigo/freshguard/internal/auth"
	"github.com/marcelorodrigo/freshguard/internal/inventory"
)

type consumedItemResponse struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

type consumedBatchResponse struct {
	ID       string `json:"id"`
	Quantity int    `json:"quantity"`
}

type consumeResponse struct {
	Item    consumedItemResponse   `json:"item"`
	Batch   *consumedBatchResponse `json:"batch"`
	Deleted bool                   `json:"deleted"`
}

type batchRequest struct {
	LocationID string `json:"location_id"`
	ExpiresAt  string `json:"expires_at"`
	Quantity   int    `json:"quantity"`
}

type updateBatchRequest struct {
	LocationID *string `json:"location_id"`
	ExpiresAt  *string `json:"expires_at"`
	Quantity   *int    `json:"quantity"`
}

func (s *server) createBatch(w http.ResponseWriter, r *http.Request, _ auth.User) {
	var req batchRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	batch, err := s.inventory.CreateBatch(r.Context(), r.PathValue("id"), inventory.BatchInput{
		LocationID: req.LocationID,
		ExpiresAt:  req.ExpiresAt,
		Quantity:   req.Quantity,
	})
	if err != nil {
		if writeInventoryError(w, err) {
			return
		}
		s.internal(w, r, "create batch", err)
		return
	}

	writeJSON(w, http.StatusCreated, newBatchResponse(batch))
}

func (s *server) updateBatch(w http.ResponseWriter, r *http.Request, _ auth.User) {
	var req updateBatchRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	batch, err := s.inventory.UpdateBatch(r.Context(), r.PathValue("id"), inventory.BatchPatch{
		LocationID: req.LocationID,
		ExpiresAt:  req.ExpiresAt,
		Quantity:   req.Quantity,
	})
	if err != nil {
		if writeInventoryError(w, err) {
			return
		}
		s.internal(w, r, "update batch", err)
		return
	}

	writeJSON(w, http.StatusOK, newBatchResponse(batch))
}

func (s *server) deleteBatch(w http.ResponseWriter, r *http.Request, _ auth.User) {
	if err := s.inventory.DeleteBatch(r.Context(), r.PathValue("id")); err != nil {
		if writeInventoryError(w, err) {
			return
		}
		s.internal(w, r, "delete batch", err)
		return
	}

	w.WriteHeader(http.StatusNoContent)
}

func (s *server) consumeBatch(w http.ResponseWriter, r *http.Request, _ auth.User) {
	result, err := s.inventory.ConsumeBatch(r.Context(), r.PathValue("id"))
	if err != nil {
		if writeInventoryError(w, err) {
			return
		}
		s.internal(w, r, "consume batch", err)
		return
	}

	response := consumeResponse{
		Item:    consumedItemResponse{ID: result.ItemID, Name: result.ItemName},
		Deleted: result.Deleted,
	}
	if !result.Deleted {
		response.Batch = &consumedBatchResponse{ID: result.BatchID, Quantity: result.BatchQuantity}
	}

	writeJSON(w, http.StatusOK, response)
}

func newBatchResponse(batch inventory.Batch) batchResponse {
	return batchResponse{
		ID:        batch.ID,
		ItemID:    batch.ItemID,
		Location:  locationRef{ID: batch.LocationID, Name: batch.LocationName},
		ExpiresAt: batch.ExpiresAt,
		Quantity:  batch.Quantity,
		CreatedAt: formatTimestamp(batch.CreatedAt),
		UpdatedAt: formatTimestamp(batch.UpdatedAt),
	}
}
