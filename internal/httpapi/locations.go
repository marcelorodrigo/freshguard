package httpapi

import (
	"encoding/json"
	"net/http"

	"github.com/marcelorodrigo/freshguard/internal/auth"
	"github.com/marcelorodrigo/freshguard/internal/inventory"
)

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

type createLocationRequest struct {
	Name                 string `json:"name"`
	Description          string `json:"description"`
	ExpirationNotifyDays int    `json:"expiration_notify_days"`
	ParentID             string `json:"parent_id"`
}

func (s *server) createLocation(w http.ResponseWriter, r *http.Request, _ auth.User) {
	var req createLocationRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	location, err := s.inventory.CreateLocation(r.Context(), inventory.LocationInput{
		Name:                 req.Name,
		Description:          req.Description,
		ExpirationNotifyDays: req.ExpirationNotifyDays,
		ParentID:             req.ParentID,
	})
	if err != nil {
		if writeInventoryError(w, err) {
			return
		}
		s.internal(w, r, "create location", err)
		return
	}

	writeJSON(w, http.StatusCreated, newLocationResponse(location))
}

type updateLocationRequest struct {
	Name                 *string        `json:"name"`
	Description          *string        `json:"description"`
	ExpirationNotifyDays *int           `json:"expiration_notify_days"`
	ParentID             optionalString `json:"parent_id"`
}

// optionalString tells an absent JSON field from an explicit null, which
// PATCH /locations/{id} needs: null clears the parent, absence leaves it.
type optionalString struct {
	set   bool
	value string
}

func (o *optionalString) UnmarshalJSON(data []byte) error {
	o.set = true
	if string(data) == "null" {
		o.value = ""
		return nil
	}
	return json.Unmarshal(data, &o.value)
}

func (o optionalString) pointer() *string {
	if !o.set {
		return nil
	}
	value := o.value
	return &value
}

func (s *server) updateLocation(w http.ResponseWriter, r *http.Request, _ auth.User) {
	var req updateLocationRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	location, err := s.inventory.UpdateLocation(r.Context(), r.PathValue("id"), inventory.LocationPatch{
		Name:                 req.Name,
		Description:          req.Description,
		ExpirationNotifyDays: req.ExpirationNotifyDays,
		ParentID:             req.ParentID.pointer(),
	})
	if err != nil {
		if writeInventoryError(w, err) {
			return
		}
		s.internal(w, r, "update location", err)
		return
	}

	writeJSON(w, http.StatusOK, newLocationResponse(location))
}

func (s *server) deleteLocation(w http.ResponseWriter, r *http.Request, _ auth.User) {
	if err := s.inventory.DeleteLocation(r.Context(), r.PathValue("id")); err != nil {
		if writeInventoryError(w, err) {
			return
		}
		s.internal(w, r, "delete location", err)
		return
	}

	w.WriteHeader(http.StatusNoContent)
}

type bulkDeleteLocationsRequest struct {
	IDs []string `json:"ids"`
}

func (s *server) bulkDeleteLocations(w http.ResponseWriter, r *http.Request, _ auth.User) {
	var req bulkDeleteLocationsRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	deleted, err := s.inventory.BulkDeleteLocations(r.Context(), req.IDs)
	if err != nil {
		if writeInventoryError(w, err) {
			return
		}
		s.internal(w, r, "bulk delete locations", err)
		return
	}

	writeJSON(w, http.StatusOK, map[string]int{"deleted": deleted})
}
