package auth

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/marcelorodrigo/freshguard/internal/store"
)

const (
	testName     = "Marcelo"
	testEmail    = "m@example.com"
	testPassword = "correct horse battery staple"
)

type fixture struct {
	svc *Service
	now time.Time
}

func newFixture(t *testing.T) *fixture {
	t.Helper()

	st, err := store.Open(t.TempDir())
	if err != nil {
		t.Fatalf("store.Open: %v", err)
	}
	t.Cleanup(func() {
		if err := st.Close(); err != nil {
			t.Errorf("store.Close: %v", err)
		}
	})

	f := &fixture{now: time.Date(2026, 1, 24, 8, 27, 26, 0, time.UTC)}
	f.svc = New(Options{
		Store: st,
		Now:   func() time.Time { return f.now },
	})

	return f
}

func (f *fixture) firstAdmin(t *testing.T) NewSession {
	t.Helper()

	session, err := f.svc.FirstAdmin(context.Background(), CreateUserInput{
		Name: testName, Email: testEmail, Password: testPassword,
	})
	if err != nil {
		t.Fatalf("FirstAdmin: %v", err)
	}
	return session
}

func assertFieldError(t *testing.T, err error, field, rule string) {
	t.Helper()

	var fieldErr *FieldError
	if !errors.As(err, &fieldErr) {
		t.Fatalf("err = %v, want a *FieldError", err)
	}
	if fieldErr.Field != field || fieldErr.Rule != rule {
		t.Errorf("FieldError = %s:%s, want %s:%s", fieldErr.Field, fieldErr.Rule, field, rule)
	}
}

func TestFirstAdminCreatesOnce(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	ctx := context.Background()

	required, err := f.svc.SetupRequired(ctx)
	if err != nil {
		t.Fatalf("SetupRequired: %v", err)
	}
	if !required {
		t.Error("SetupRequired on an empty database = false, want true")
	}

	created := f.firstAdmin(t)
	if !created.User.IsAdmin {
		t.Error("first user is not an admin, want is_admin")
	}
	if created.Secret == "" {
		t.Error("FirstAdmin returned no session secret")
	}
	if want := f.now.Add(DefaultSessionTTL); !created.ExpiresAt.Equal(want) {
		t.Errorf("expires_at = %v, want %v", created.ExpiresAt, want)
	}

	required, err = f.svc.SetupRequired(ctx)
	if err != nil {
		t.Fatalf("SetupRequired after setup: %v", err)
	}
	if required {
		t.Error("SetupRequired after setup = true, want false")
	}

	session, err := f.svc.Session(ctx, created.Secret)
	if err != nil {
		t.Fatalf("Session with the setup secret: %v", err)
	}
	if session.User.ID != created.User.ID {
		t.Errorf("session user = %s, want %s", session.User.ID, created.User.ID)
	}

	_, err = f.svc.FirstAdmin(ctx, CreateUserInput{
		Name: "Someone", Email: "someone@example.com", Password: testPassword,
	})
	if !errors.Is(err, ErrSetupComplete) {
		t.Errorf("second FirstAdmin = %v, want ErrSetupComplete", err)
	}
}

func TestFirstAdminValidatesItsInput(t *testing.T) {
	t.Parallel()

	tests := map[string]struct {
		in    CreateUserInput
		field string
		rule  string
	}{
		"missing name":            {in: CreateUserInput{Email: testEmail, Password: testPassword}, field: "name", rule: "required"},
		"blank name":              {in: CreateUserInput{Name: "   ", Email: testEmail, Password: testPassword}, field: "name", rule: "required"},
		"missing email":           {in: CreateUserInput{Name: testName, Password: testPassword}, field: "email", rule: "required"},
		"invalid email":           {in: CreateUserInput{Name: testName, Email: "not-an-email", Password: testPassword}, field: "email", rule: "email"},
		"short password":          {in: CreateUserInput{Name: testName, Email: testEmail, Password: "short"}, field: "password", rule: "min"},
		"too long password":       {in: CreateUserInput{Name: testName, Email: testEmail, Password: strings.Repeat("a", 256)}, field: "password", rule: "max"},
		"display name as address": {in: CreateUserInput{Name: testName, Email: "Marcelo <m@example.com>", Password: testPassword}, field: "email", rule: "email"},
	}

	for name, tc := range tests {
		t.Run(name, func(t *testing.T) {
			f := newFixture(t)
			_, err := f.svc.FirstAdmin(context.Background(), tc.in)
			assertFieldError(t, err, tc.field, tc.rule)
		})
	}
}

func TestLogin(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	f.firstAdmin(t)
	ctx := context.Background()

	tests := map[string]struct {
		email    string
		password string
		want     error
	}{
		"correct credentials":    {email: testEmail, password: testPassword},
		"case-insensitive email": {email: "M@EXAMPLE.COM", password: testPassword},
		"wrong password":         {email: testEmail, password: "wrong password", want: ErrInvalidCredentials},
		"unknown email":          {email: "nobody@example.com", password: testPassword, want: ErrInvalidCredentials},
	}

	for name, tc := range tests {
		session, err := f.svc.Login(ctx, tc.email, tc.password, "test-agent")
		if tc.want != nil {
			if !errors.Is(err, tc.want) {
				t.Errorf("%s: err = %v, want %v", name, err, tc.want)
			}
			continue
		}
		if err != nil {
			t.Errorf("%s: err = %v, want success", name, err)
			continue
		}
		if session.Secret == "" {
			t.Errorf("%s: no session secret", name)
		}
	}
}

func TestSessionExpiresAndSlides(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	created := f.firstAdmin(t)
	ctx := context.Background()

	f.now = f.now.Add(59 * time.Minute)
	session, err := f.svc.Session(ctx, created.Secret)
	if err != nil {
		t.Fatalf("Session after 59 minutes: %v", err)
	}
	if session.Extended {
		t.Error("session slid before the refresh interval, want it untouched")
	}
	if !session.ExpiresAt.Equal(created.ExpiresAt) {
		t.Errorf("expiry = %v, want the original %v", session.ExpiresAt, created.ExpiresAt)
	}

	f.now = f.now.Add(2 * time.Hour)
	session, err = f.svc.Session(ctx, created.Secret)
	if err != nil {
		t.Fatalf("Session after two hours: %v", err)
	}
	if !session.Extended {
		t.Error("session past the refresh interval did not slide")
	}
	if want := f.now.Add(DefaultSessionTTL); !session.ExpiresAt.Equal(want) {
		t.Errorf("slid expiry = %v, want %v", session.ExpiresAt, want)
	}

	f.now = f.now.Add(DefaultSessionTTL)
	_, err = f.svc.Session(ctx, created.Secret)
	if !errors.Is(err, ErrSessionInvalid) {
		t.Errorf("Session after the TTL = %v, want ErrSessionInvalid", err)
	}
}

func TestSessionRejectsUnknownSecret(t *testing.T) {
	t.Parallel()

	f := newFixture(t)

	if _, err := f.svc.Session(context.Background(), "not-a-session"); !errors.Is(err, ErrSessionInvalid) {
		t.Errorf("Session(unknown) = %v, want ErrSessionInvalid", err)
	}
	if _, err := f.svc.Session(context.Background(), ""); !errors.Is(err, ErrSessionInvalid) {
		t.Errorf("Session(empty) = %v, want ErrSessionInvalid", err)
	}
}

func TestRevokeSession(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	created := f.firstAdmin(t)
	ctx := context.Background()

	if err := f.svc.RevokeSession(ctx, created.Secret); err != nil {
		t.Fatalf("RevokeSession: %v", err)
	}
	if _, err := f.svc.Session(ctx, created.Secret); !errors.Is(err, ErrSessionInvalid) {
		t.Errorf("Session after logout = %v, want ErrSessionInvalid", err)
	}
	if err := f.svc.RevokeSession(ctx, created.Secret); err != nil {
		t.Errorf("second RevokeSession = %v, want nil (logout is idempotent)", err)
	}
}

func TestUpdateProfile(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	created := f.firstAdmin(t)
	ctx := context.Background()

	name := "Ana"
	updated, err := f.svc.UpdateProfile(ctx, created.User.ID, ProfilePatch{Name: &name})
	if err != nil {
		t.Fatalf("UpdateProfile(name): %v", err)
	}
	if updated.Name != name {
		t.Errorf("name = %q, want %q", updated.Name, name)
	}
	if updated.Email != testEmail {
		t.Errorf("email = %q, want it unchanged", updated.Email)
	}

	email := "ana@example.com"
	updated, err = f.svc.UpdateProfile(ctx, created.User.ID, ProfilePatch{Email: &email})
	if err != nil {
		t.Fatalf("UpdateProfile(email): %v", err)
	}
	if updated.Email != email {
		t.Errorf("email = %q, want %q", updated.Email, email)
	}
}

func TestUpdateProfileRejectsTakenEmail(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	created := f.firstAdmin(t)
	ctx := context.Background()

	if _, err := f.svc.CreateUser(ctx, CreateUserInput{
		Name: "Ana", Email: "ana@example.com", Password: testPassword,
	}); err != nil {
		t.Fatalf("CreateUser: %v", err)
	}

	taken := "ANA@example.com"
	_, err := f.svc.UpdateProfile(ctx, created.User.ID, ProfilePatch{Email: &taken})
	assertFieldError(t, err, "email", "unique")
}

func TestUpdateProfilePassword(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	created := f.firstAdmin(t)
	ctx := context.Background()

	newPassword := "a much better password"
	wrong := "not the current one"
	current := testPassword

	_, err := f.svc.UpdateProfile(ctx, created.User.ID, ProfilePatch{Password: &newPassword, CurrentPassword: &wrong})
	assertFieldError(t, err, "current_password", "mismatch")

	_, err = f.svc.UpdateProfile(ctx, created.User.ID, ProfilePatch{Password: &newPassword})
	assertFieldError(t, err, "current_password", "required")

	short := "short"
	_, err = f.svc.UpdateProfile(ctx, created.User.ID, ProfilePatch{Password: &short, CurrentPassword: &current})
	assertFieldError(t, err, "password", "min")

	if _, err := f.svc.UpdateProfile(ctx, created.User.ID, ProfilePatch{
		Password: &newPassword, CurrentPassword: &current,
	}); err != nil {
		t.Fatalf("UpdateProfile(password): %v", err)
	}

	if _, err := f.svc.Login(ctx, testEmail, testPassword, ""); !errors.Is(err, ErrInvalidCredentials) {
		t.Errorf("login with the old password = %v, want ErrInvalidCredentials", err)
	}
	if _, err := f.svc.Login(ctx, testEmail, newPassword, ""); err != nil {
		t.Errorf("login with the new password = %v, want success", err)
	}
}

func TestSetTemporaryPasswordForcesAReset(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	f.firstAdmin(t)
	ctx := context.Background()

	member, err := f.svc.CreateUser(ctx, CreateUserInput{
		Name: "Ana", Email: "ana@example.com", Password: testPassword,
	})
	if err != nil {
		t.Fatalf("CreateUser: %v", err)
	}

	memberSession, err := f.svc.Login(ctx, member.Email, testPassword, "")
	if err != nil {
		t.Fatalf("Login(member): %v", err)
	}

	temporary := "temporary password"
	if err := f.svc.SetTemporaryPassword(ctx, member.ID, temporary); err != nil {
		t.Fatalf("SetTemporaryPassword: %v", err)
	}

	if _, err := f.svc.Session(ctx, memberSession.Secret); !errors.Is(err, ErrSessionInvalid) {
		t.Errorf("member session after a temporary password = %v, want ErrSessionInvalid", err)
	}

	session, err := f.svc.Login(ctx, member.Email, temporary, "")
	if err != nil {
		t.Fatalf("Login with the temporary password: %v", err)
	}
	if !session.User.MustReset {
		t.Error("must_reset = false after a temporary password, want true")
	}

	chosen := "the password they chose"
	if _, err := f.svc.UpdateProfile(ctx, member.ID, ProfilePatch{
		Password: &chosen, CurrentPassword: &temporary,
	}); err != nil {
		t.Fatalf("UpdateProfile after must_reset: %v", err)
	}

	session, err = f.svc.Login(ctx, member.Email, chosen, "")
	if err != nil {
		t.Fatalf("Login with the chosen password: %v", err)
	}
	if session.User.MustReset {
		t.Error("must_reset = true after the user chose a password, want false")
	}
}

func TestPasswordReset(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	f.firstAdmin(t)
	ctx := context.Background()

	adminSession, err := f.svc.Login(ctx, testEmail, testPassword, "")
	if err != nil {
		t.Fatalf("Login: %v", err)
	}

	token, err := f.svc.CreateResetToken(ctx, testEmail)
	if err != nil {
		t.Fatalf("CreateResetToken: %v", err)
	}
	if token.Secret == "" {
		t.Fatal("CreateResetToken returned no secret")
	}
	if want := f.now.Add(PasswordResetTTL); !token.ExpiresAt.Equal(want) {
		t.Errorf("expires_at = %v, want %v", token.ExpiresAt, want)
	}

	email, err := f.svc.ResetTokenEmail(ctx, token.Secret)
	if err != nil {
		t.Fatalf("ResetTokenEmail: %v", err)
	}
	if email != testEmail {
		t.Errorf("email = %q, want %q", email, testEmail)
	}

	newPassword := "the password they chose"
	if err := f.svc.ResetPassword(ctx, token.Secret, newPassword); err != nil {
		t.Fatalf("ResetPassword: %v", err)
	}

	if _, err := f.svc.Session(ctx, adminSession.Secret); !errors.Is(err, ErrSessionInvalid) {
		t.Errorf("session after a reset = %v, want ErrSessionInvalid", err)
	}
	if _, err := f.svc.ResetTokenEmail(ctx, token.Secret); !errors.Is(err, ErrResetTokenInvalid) {
		t.Errorf("used token = %v, want ErrResetTokenInvalid", err)
	}
	if err := f.svc.ResetPassword(ctx, token.Secret, "another password"); !errors.Is(err, ErrResetTokenInvalid) {
		t.Errorf("second ResetPassword = %v, want ErrResetTokenInvalid", err)
	}

	if _, err := f.svc.Login(ctx, testEmail, newPassword, ""); err != nil {
		t.Errorf("login with the reset password = %v, want success", err)
	}
}

func TestPasswordResetTokenExpires(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	f.firstAdmin(t)
	ctx := context.Background()

	token, err := f.svc.CreateResetToken(ctx, testEmail)
	if err != nil {
		t.Fatalf("CreateResetToken: %v", err)
	}

	f.now = f.now.Add(PasswordResetTTL)

	if _, err := f.svc.ResetTokenEmail(ctx, token.Secret); !errors.Is(err, ErrResetTokenInvalid) {
		t.Errorf("expired token = %v, want ErrResetTokenInvalid", err)
	}
	if err := f.svc.ResetPassword(ctx, token.Secret, "the password they chose"); !errors.Is(err, ErrResetTokenInvalid) {
		t.Errorf("ResetPassword with an expired token = %v, want ErrResetTokenInvalid", err)
	}
}

func TestCreateResetTokenInvalidatesThePreviousOne(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	f.firstAdmin(t)
	ctx := context.Background()

	first, err := f.svc.CreateResetToken(ctx, testEmail)
	if err != nil {
		t.Fatalf("first CreateResetToken: %v", err)
	}
	if _, err := f.svc.CreateResetToken(ctx, testEmail); err != nil {
		t.Fatalf("second CreateResetToken: %v", err)
	}

	if _, err := f.svc.ResetTokenEmail(ctx, first.Secret); !errors.Is(err, ErrResetTokenInvalid) {
		t.Errorf("superseded token = %v, want ErrResetTokenInvalid", err)
	}
}

func TestCreateResetTokenForUnknownEmail(t *testing.T) {
	t.Parallel()

	f := newFixture(t)

	if _, err := f.svc.CreateResetToken(context.Background(), "nobody@example.com"); !errors.Is(err, ErrUserNotFound) {
		t.Errorf("CreateResetToken(unknown) = %v, want ErrUserNotFound", err)
	}
}

func TestCreateUserFirstIsAdmin(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	ctx := context.Background()

	first, err := f.svc.CreateUser(ctx, CreateUserInput{
		Name: "Marcelo", Email: testEmail, Password: testPassword,
	})
	if err != nil {
		t.Fatalf("CreateUser(first): %v", err)
	}
	if !first.IsAdmin {
		t.Error("the first account is not an admin, want is_admin implied")
	}

	second, err := f.svc.CreateUser(ctx, CreateUserInput{
		Name: "Ana", Email: "ana@example.com", Password: testPassword,
	})
	if err != nil {
		t.Fatalf("CreateUser(second): %v", err)
	}
	if second.IsAdmin {
		t.Error("the second account is an admin, want the explicit flag to decide")
	}
}

func TestUserCount(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	ctx := context.Background()

	count, err := f.svc.UserCount(ctx)
	if err != nil {
		t.Fatalf("UserCount: %v", err)
	}
	if count != 0 {
		t.Errorf("count = %d, want 0", count)
	}

	f.firstAdmin(t)

	count, err = f.svc.UserCount(ctx)
	if err != nil {
		t.Fatalf("UserCount: %v", err)
	}
	if count != 1 {
		t.Errorf("count = %d, want 1", count)
	}
}
