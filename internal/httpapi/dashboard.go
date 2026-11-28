package httpapi

import (
	"net/http"

	"github.com/marcelorodrigo/freshguard/internal/auth"
	"github.com/marcelorodrigo/freshguard/internal/inventory"
)

// itemRef names the item a dashboard row belongs to.
type itemRef struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

type expiredBatchResponse struct {
	batchResponse
	Item itemRef `json:"item"`
}

type expiredBatchesResponse struct {
	Data []expiredBatchResponse `json:"data"`
}

func newExpiredBatchResponse(batch inventory.ExpiredBatch) expiredBatchResponse {
	return expiredBatchResponse{
		batchResponse: newBatchResponse(batch.Batch),
		Item:          itemRef{ID: batch.ItemID, Name: batch.ItemName},
	}
}

func (s *server) recentlyExpired(w http.ResponseWriter, r *http.Request, _ auth.User) {
	batches, err := s.inventory.RecentlyExpired(r.Context())
	if err != nil {
		s.internal(w, r, "recently expired", err)
		return
	}

	data := make([]expiredBatchResponse, len(batches))
	for i, batch := range batches {
		data[i] = newExpiredBatchResponse(batch)
	}

	writeJSON(w, http.StatusOK, expiredBatchesResponse{Data: data})
}
