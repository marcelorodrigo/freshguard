package httpapi

import (
	"net/http"

	"github.com/marcelorodrigo/freshguard/internal/auth"
)

type usersResponse struct {
	Data []userResponse `json:"data"`
	Meta pageMeta       `json:"meta"`
}

func (s *server) listUsers(w http.ResponseWriter, r *http.Request, _ auth.User) {
	query := r.URL.Query()

	page, err := s.auth.ListUsers(r.Context(), auth.ListUsersParams{
		Q:       query.Get("q"),
		Sort:    query.Get("sort"),
		Dir:     query.Get("dir"),
		Page:    queryInt(r, "page"),
		PerPage: queryInt(r, "per_page"),
	})
	if err != nil {
		s.internal(w, r, "list users", err)
		return
	}

	users := make([]userResponse, len(page.Users))
	for i, user := range page.Users {
		users[i] = newUserResponse(user)
	}

	writeJSON(w, http.StatusOK, usersResponse{
		Data: users,
		Meta: pageMeta{
			Page:    page.Page,
			PerPage: page.PerPage,
			Total:   page.Total,
			Pages:   page.Pages,
		},
	})
}

type createUserRequest struct {
	Name     string `json:"name"`
	Email    string `json:"email"`
	Password string `json:"password"`
	IsAdmin  bool   `json:"is_admin"`
}

func (s *server) createUser(w http.ResponseWriter, r *http.Request, _ auth.User) {
	var req createUserRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	user, err := s.auth.CreateUser(r.Context(), auth.CreateUserInput{
		Name:     req.Name,
		Email:    req.Email,
		Password: req.Password,
		IsAdmin:  req.IsAdmin,
	})
	if err != nil {
		if writeAuthError(w, err) {
			return
		}
		s.internal(w, r, "create user", err)
		return
	}

	writeJSON(w, http.StatusCreated, newUserResponse(user))
}

type updateUserRequest struct {
	Name     *string `json:"name"`
	Email    *string `json:"email"`
	IsAdmin  *bool   `json:"is_admin"`
	Password *string `json:"password"`
}

func (s *server) updateUser(w http.ResponseWriter, r *http.Request, actor auth.User) {
	var req updateUserRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	user, err := s.auth.AdminUpdateUser(r.Context(), actor.ID, r.PathValue("id"), auth.AdminUserPatch{
		Name:     req.Name,
		Email:    req.Email,
		IsAdmin:  req.IsAdmin,
		Password: req.Password,
	})
	if err != nil {
		if writeAuthError(w, err) {
			return
		}
		s.internal(w, r, "update user", err)
		return
	}

	writeJSON(w, http.StatusOK, newUserResponse(user))
}

func (s *server) deleteUser(w http.ResponseWriter, r *http.Request, actor auth.User) {
	if err := s.auth.DeleteUser(r.Context(), actor.ID, r.PathValue("id")); err != nil {
		if writeAuthError(w, err) {
			return
		}
		s.internal(w, r, "delete user", err)
		return
	}

	w.WriteHeader(http.StatusNoContent)
}

type setUserPasswordRequest struct {
	Password string `json:"password"`
}

func (s *server) setUserPassword(w http.ResponseWriter, r *http.Request, _ auth.User) {
	var req setUserPasswordRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	keepSession := ""
	if cookie, err := r.Cookie(sessionCookieName); err == nil {
		keepSession = cookie.Value
	}

	if err := s.auth.SetTemporaryPassword(r.Context(), r.PathValue("id"), req.Password, keepSession); err != nil {
		if writeAuthError(w, err) {
			return
		}
		s.internal(w, r, "set temporary password", err)
		return
	}

	w.WriteHeader(http.StatusNoContent)
}
