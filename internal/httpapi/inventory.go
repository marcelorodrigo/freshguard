package httpapi

import (
	"errors"
	"net/http"
	"strconv"
	"strings"

	"github.com/marcelorodrigo/freshguard/internal/auth"
	"github.com/marcelorodrigo/freshguard/internal/inventory"
)

type itemResponse struct {
	ID                 string   `json:"id"`
	Name               string   `json:"name"`
	Barcode            string   `json:"barcode"`
	Description        string   `json:"description"`
	Tags               []string `json:"tags"`
	Quantity           int      `json:"quantity"`
	EarliestExpiration string   `json:"earliest_expiration"`
	BatchCount         int      `json:"batch_count"`
	CreatedAt          string   `json:"created_at"`
	UpdatedAt          string   `json:"updated_at"`
}

type itemDetailResponse struct {
	itemResponse
	Batches []batchResponse `json:"batches"`
}

type locationRef struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

type batchResponse struct {
	ID        string      `json:"id"`
	ItemID    string      `json:"item_id"`
	Location  locationRef `json:"location"`
	ExpiresAt string      `json:"expires_at"`
	Quantity  int         `json:"quantity"`
	CreatedAt string      `json:"created_at"`
	UpdatedAt string      `json:"updated_at"`
}

type pageMeta struct {
	Page    int `json:"page"`
	PerPage int `json:"per_page"`
	Total   int `json:"total"`
	Pages   int `json:"pages"`
}

type itemsResponse struct {
	Data []itemResponse `json:"data"`
	Meta pageMeta       `json:"meta"`
}

type tagResponse struct {
	ID        string `json:"id"`
	Name      string `json:"name"`
	ItemCount int    `json:"item_count"`
}

type tagsResponse struct {
	Data []tagResponse `json:"data"`
}

func newItemResponse(item inventory.Item) itemResponse {
	tags := item.Tags
	if tags == nil {
		tags = []string{}
	}

	return itemResponse{
		ID:                 item.ID,
		Name:               item.Name,
		Barcode:            item.Barcode,
		Description:        item.Description,
		Tags:               tags,
		Quantity:           item.Quantity,
		EarliestExpiration: item.EarliestExpiration,
		BatchCount:         item.BatchCount,
		CreatedAt:          formatTimestamp(item.CreatedAt),
		UpdatedAt:          formatTimestamp(item.UpdatedAt),
	}
}

func newItemDetailResponse(item inventory.Item) itemDetailResponse {
	batches := make([]batchResponse, len(item.Batches))
	for i, batch := range item.Batches {
		batches[i] = newBatchResponse(batch)
	}

	return itemDetailResponse{
		itemResponse: newItemResponse(item),
		Batches:      batches,
	}
}

func (s *server) listItems(w http.ResponseWriter, r *http.Request, _ auth.User) {
	query := r.URL.Query()

	page, err := s.inventory.ListItems(r.Context(), inventory.ListParams{
		Q:       query.Get("q"),
		Sort:    query.Get("sort"),
		Dir:     query.Get("dir"),
		Page:    queryInt(r, "page"),
		PerPage: queryInt(r, "per_page"),
	})
	if err != nil {
		s.internal(w, r, "list items", err)
		return
	}

	items := make([]itemResponse, len(page.Items))
	for i, item := range page.Items {
		items[i] = newItemResponse(item)
	}

	writeJSON(w, http.StatusOK, itemsResponse{
		Data: items,
		Meta: pageMeta{
			Page:    page.Page,
			PerPage: page.PerPage,
			Total:   page.Total,
			Pages:   page.Pages,
		},
	})
}

func (s *server) getItem(w http.ResponseWriter, r *http.Request, _ auth.User) {
	item, err := s.inventory.Get(r.Context(), r.PathValue("id"))
	if err != nil {
		if writeInventoryError(w, err) {
			return
		}
		s.internal(w, r, "get item", err)
		return
	}

	writeJSON(w, http.StatusOK, newItemDetailResponse(item))
}

type createItemRequest struct {
	Name        string   `json:"name"`
	Barcode     string   `json:"barcode"`
	Description string   `json:"description"`
	Tags        []string `json:"tags"`
}

func (s *server) createItem(w http.ResponseWriter, r *http.Request, _ auth.User) {
	var req createItemRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	item, err := s.inventory.CreateItem(r.Context(), inventory.ItemInput{
		Name:        req.Name,
		Barcode:     req.Barcode,
		Description: req.Description,
		Tags:        req.Tags,
	})
	if err != nil {
		if writeInventoryError(w, err) {
			return
		}
		s.internal(w, r, "create item", err)
		return
	}

	writeJSON(w, http.StatusCreated, newItemDetailResponse(item))
}

type updateItemRequest struct {
	Name        *string   `json:"name"`
	Barcode     *string   `json:"barcode"`
	Description *string   `json:"description"`
	Tags        *[]string `json:"tags"`
}

func (s *server) updateItem(w http.ResponseWriter, r *http.Request, _ auth.User) {
	var req updateItemRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	item, err := s.inventory.UpdateItem(r.Context(), r.PathValue("id"), inventory.ItemPatch{
		Name:        req.Name,
		Barcode:     req.Barcode,
		Description: req.Description,
		Tags:        req.Tags,
	})
	if err != nil {
		if writeInventoryError(w, err) {
			return
		}
		s.internal(w, r, "update item", err)
		return
	}

	writeJSON(w, http.StatusOK, newItemDetailResponse(item))
}

func (s *server) deleteItem(w http.ResponseWriter, r *http.Request, _ auth.User) {
	if err := s.inventory.DeleteItem(r.Context(), r.PathValue("id")); err != nil {
		if writeInventoryError(w, err) {
			return
		}
		s.internal(w, r, "delete item", err)
		return
	}

	w.WriteHeader(http.StatusNoContent)
}

type bulkDeleteItemsRequest struct {
	IDs []string `json:"ids"`
}

func (s *server) bulkDeleteItems(w http.ResponseWriter, r *http.Request, _ auth.User) {
	var req bulkDeleteItemsRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	deleted, err := s.inventory.BulkDelete(r.Context(), req.IDs)
	if err != nil {
		s.internal(w, r, "bulk delete items", err)
		return
	}

	writeJSON(w, http.StatusOK, map[string]int{"deleted": deleted})
}

func (s *server) listTags(w http.ResponseWriter, r *http.Request, _ auth.User) {
	tags, err := s.inventory.Tags(r.Context())
	if err != nil {
		s.internal(w, r, "list tags", err)
		return
	}

	data := make([]tagResponse, len(tags))
	for i, tag := range tags {
		data[i] = tagResponse{ID: tag.ID, Name: tag.Name, ItemCount: tag.ItemCount}
	}

	writeJSON(w, http.StatusOK, tagsResponse{Data: data})
}

// writeInventoryError maps an inventory rule to the envelope. It reports false
// for an unexpected error, which the caller logs and answers as 500.
func writeInventoryError(w http.ResponseWriter, err error) bool {
	var fieldErr *inventory.FieldError
	var hasBatches *inventory.LocationHasBatchesError

	switch {
	case errors.As(err, &fieldErr):
		writeJSON(w, http.StatusUnprocessableEntity, errorResponse{Error: errorBody{
			Code:    CodeInvalidInput,
			Message: "invalid input",
			Fields:  map[string]string{fieldErr.Field: fieldErr.Rule},
		}})
	case errors.As(err, &hasBatches):
		writeJSON(w, http.StatusConflict, errorResponse{Error: errorBody{
			Code:    CodeLocationHasBatches,
			Message: "location still holds batches",
			Fields:  map[string]string{"ids": strings.Join(hasBatches.IDs, ",")},
		}})
	case errors.Is(err, inventory.ErrItemNotFound):
		writeError(w, http.StatusNotFound, CodeNotFound, "no such item")
	case errors.Is(err, inventory.ErrBatchNotFound):
		writeError(w, http.StatusNotFound, CodeNotFound, "no such batch")
	case errors.Is(err, inventory.ErrBatchUnavailable):
		writeError(w, http.StatusConflict, CodeBatchUnavailable, "batch is no longer available")
	case errors.Is(err, inventory.ErrLocationNotFound):
		writeError(w, http.StatusNotFound, CodeNotFound, "no such location")
	default:
		return false
	}

	return true
}

// queryInt reads a positive integer query parameter; invalid values are left
// to the service, whose defaults match docs/api.md.
func queryInt(r *http.Request, name string) int {
	value, err := strconv.Atoi(r.URL.Query().Get(name))
	if err != nil {
		return 0
	}
	return value
}
