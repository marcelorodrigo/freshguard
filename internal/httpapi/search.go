package httpapi

import (
	"net/http"

	"github.com/marcelorodrigo/freshguard/internal/auth"
)

type searchBatchResponse struct {
	ID        string      `json:"id"`
	Location  locationRef `json:"location"`
	ExpiresAt string      `json:"expires_at"`
	Quantity  int         `json:"quantity"`
}

type searchItemResponse struct {
	ID          string                `json:"id"`
	Name        string                `json:"name"`
	Description string                `json:"description"`
	Batches     []searchBatchResponse `json:"batches"`
}

type searchResponse struct {
	Data []searchItemResponse `json:"data"`
}

// search answers the Quick Consume list. A short term is not an error: the
// endpoint returns the empty list the screen renders as its initial state.
func (s *server) search(w http.ResponseWriter, r *http.Request, _ auth.User) {
	results, err := s.inventory.Search(r.Context(), r.URL.Query().Get("q"))
	if err != nil {
		s.internal(w, r, "search items", err)
		return
	}

	data := make([]searchItemResponse, len(results))
	for i, result := range results {
		batches := make([]searchBatchResponse, len(result.Batches))
		for j, batch := range result.Batches {
			batches[j] = searchBatchResponse{
				ID:        batch.ID,
				Location:  locationRef{ID: batch.LocationID, Name: batch.LocationName},
				ExpiresAt: batch.ExpiresAt,
				Quantity:  batch.Quantity,
			}
		}

		data[i] = searchItemResponse{
			ID:          result.ID,
			Name:        result.Name,
			Description: result.Description,
			Batches:     batches,
		}
	}

	writeJSON(w, http.StatusOK, searchResponse{Data: data})
}
