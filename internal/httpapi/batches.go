package httpapi

import (
	"net/http"

	"github.com/marcelorodrigo/freshguard/internal/auth"
	"github.com/marcelorodrigo/freshguard/internal/inventory"
)

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

type locationResponse struct {
	ID                   string       `json:"id"`
	Name                 string       `json:"name"`
	Description          string       `json:"description"`
	ExpirationNotifyDays int          `json:"expiration_notify_days"`
	Parent               *locationRef `json:"parent"`
	BatchCount           int          `json:"batch_count"`
	CreatedAt            string       `json:"created_at"`
	UpdatedAt            string       `json:"updated_at"`
}

type locationsResponse struct {
	Data []locationResponse `json:"data"`
	Meta pageMeta           `json:"meta"`
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

func newLocationResponse(location inventory.Location) locationResponse {
	var parent *locationRef
	if location.ParentID != "" {
		parent = &locationRef{ID: location.ParentID, Name: location.ParentName}
	}

	return locationResponse{
		ID:                   location.ID,
		Name:                 location.Name,
		Description:          location.Description,
		ExpirationNotifyDays: location.ExpirationNotifyDays,
		Parent:               parent,
		BatchCount:           location.BatchCount,
		CreatedAt:            formatTimestamp(location.CreatedAt),
		UpdatedAt:            formatTimestamp(location.UpdatedAt),
	}
}

func (s *server) listLocations(w http.ResponseWriter, r *http.Request, _ auth.User) {
	query := r.URL.Query()

	page, err := s.inventory.ListLocations(r.Context(), inventory.ListParams{
		Q:       query.Get("q"),
		Sort:    query.Get("sort"),
		Dir:     query.Get("dir"),
		Page:    queryInt(r, "page"),
		PerPage: queryInt(r, "per_page"),
	})
	if err != nil {
		s.internal(w, r, "list locations", err)
		return
	}

	locations := make([]locationResponse, len(page.Locations))
	for i, location := range page.Locations {
		locations[i] = newLocationResponse(location)
	}

	writeJSON(w, http.StatusOK, locationsResponse{
		Data: locations,
		Meta: pageMeta{
			Page:    page.Page,
			PerPage: page.PerPage,
			Total:   page.Total,
			Pages:   page.Pages,
		},
	})
}
