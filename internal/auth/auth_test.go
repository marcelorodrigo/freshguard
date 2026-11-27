package auth

import (
	"context"
	"errors"
	"strings"
	"sync"
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

	if _, err := f.svc.Login(ctx, email, testPassword, ""); err != nil {
		t.Errorf("login after a profile edit = %v, want the password unchanged", err)
	}
}

func TestUpdateProfileWithoutChanges(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	created := f.firstAdmin(t)

	user, err := f.svc.UpdateProfile(context.Background(), created.User.ID, ProfilePatch{})
	if err != nil {
		t.Fatalf("UpdateProfile(empty patch): %v", err)
	}
	if user.ID != created.User.ID {
		t.Errorf("user id = %s, want %s", user.ID, created.User.ID)
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

func TestResetPasswordRedeemsTokenOnce(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	f.firstAdmin(t)
	ctx := context.Background()

	token, err := f.svc.CreateResetToken(ctx, testEmail)
	if err != nil {
		t.Fatalf("CreateResetToken: %v", err)
	}

	start := make(chan struct{})
	errs := make(chan error, 2)
	var wg sync.WaitGroup

	for _, password := range []string{"first new password", "second new password"} {
		wg.Add(1)
		go func(password string) {
			defer wg.Done()
			<-start
			errs <- f.svc.ResetPassword(ctx, token.Secret, password)
		}(password)
	}
	close(start)
	wg.Wait()
	close(errs)

	successes, invalid := 0, 0
	for err := range errs {
		switch {
		case err == nil:
			successes++
		case errors.Is(err, ErrResetTokenInvalid):
			invalid++
		default:
			t.Errorf("ResetPassword error = %v, want success or ErrResetTokenInvalid", err)
		}
	}
	if successes != 1 || invalid != 1 {
		t.Fatalf("reset attempts = %d success, %d invalid; want exactly one of each", successes, invalid)
	}

	workingPasswords := 0
	for _, password := range []string{"first new password", "second new password"} {
		if _, err := f.svc.Login(ctx, testEmail, password, ""); err == nil {
			workingPasswords++
		}
	}
	if workingPasswords != 1 {
		t.Errorf("passwords that authenticate = %d, want exactly the winning one", workingPasswords)
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

func TestResetTokensFailOnACancelledContext(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	f.firstAdmin(t)

	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	if _, err := f.svc.CreateResetToken(ctx, testEmail); err == nil {
		t.Error("CreateResetToken on a cancelled context = nil error, want failure")
	}
	if _, err := f.svc.ResetTokenEmail(ctx, "token"); err == nil {
		t.Error("ResetTokenEmail on a cancelled context = nil error, want failure")
	}
	if err := f.svc.ResetPassword(ctx, "token", testPassword); err == nil {
		t.Error("ResetPassword on a cancelled context = nil error, want failure")
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

func TestUpdateProfileOfUnknownUser(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	name := "Ana"
	password := "a much better password"
	current := testPassword
	unknown := "0190a1b2-0000-7000-8000-000000000000"

	_, err := f.svc.UpdateProfile(context.Background(), unknown, ProfilePatch{Name: &name})
	if !errors.Is(err, ErrUserNotFound) {
		t.Errorf("UpdateProfile(unknown) = %v, want ErrUserNotFound", err)
	}

	_, err = f.svc.UpdateProfile(context.Background(), unknown, ProfilePatch{
		Password: &password, CurrentPassword: &current,
	})
	if !errors.Is(err, ErrUserNotFound) {
		t.Errorf("UpdateProfile(unknown, password) = %v, want ErrUserNotFound", err)
	}
}

func TestRevokeSessionWithEmptySecret(t *testing.T) {
	t.Parallel()

	f := newFixture(t)

	if err := f.svc.RevokeSession(context.Background(), ""); err != nil {
		t.Errorf("RevokeSession(empty) = %v, want nil", err)
	}
}

func TestUpdateProfilePasswordWithProfileFields(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	created := f.firstAdmin(t)
	ctx := context.Background()

	name := "  Ana  "
	email := "ana@example.com"
	newPassword := "a much better password"
	current := testPassword

	updated, err := f.svc.UpdateProfile(ctx, created.User.ID, ProfilePatch{
		Name: &name, Email: &email, Password: &newPassword, CurrentPassword: &current,
	})
	if err != nil {
		t.Fatalf("UpdateProfile(password with profile fields): %v", err)
	}
	if updated.Name != "Ana" || updated.Email != email {
		t.Errorf("user = %+v, want the trimmed name and new email", updated)
	}

	if _, err := f.svc.Login(ctx, email, newPassword, ""); err != nil {
		t.Errorf("login after the change = %v, want success", err)
	}
}

func TestUpdateProfilePasswordValidatesProfileFields(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	created := f.firstAdmin(t)
	ctx := context.Background()

	newPassword := "a much better password"
	current := testPassword
	longName := strings.Repeat("a", 256)
	badEmail := "not-an-email"

	_, err := f.svc.UpdateProfile(ctx, created.User.ID, ProfilePatch{
		Name: &longName, Password: &newPassword, CurrentPassword: &current,
	})
	assertFieldError(t, err, "name", "max")

	_, err = f.svc.UpdateProfile(ctx, created.User.ID, ProfilePatch{
		Email: &badEmail, Password: &newPassword, CurrentPassword: &current,
	})
	assertFieldError(t, err, "email", "email")
}

func TestUpdateProfilePasswordRejectsTakenEmail(t *testing.T) {
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
	newPassword := "a much better password"
	current := testPassword

	_, err := f.svc.UpdateProfile(ctx, created.User.ID, ProfilePatch{
		Email: &taken, Password: &newPassword, CurrentPassword: &current,
	})
	assertFieldError(t, err, "email", "unique")
}

func TestWriteProfilePasswordRejectsAStaleHash(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	created := f.firstAdmin(t)
	ctx := context.Background()

	user, hash, err := f.svc.userWithHashByID(ctx, created.User.ID)
	if err != nil {
		t.Fatalf("userWithHashByID: %v", err)
	}

	// A reset that lands after the read changes password_hash.
	if _, err := f.svc.db.Writer().ExecContext(ctx,
		"UPDATE users SET password_hash = ? WHERE id = ?", "a-newer-hash", user.ID); err != nil {
		t.Fatalf("change password hash: %v", err)
	}

	newHash, err := HashPassword("a much better password")
	if err != nil {
		t.Fatalf("HashPassword: %v", err)
	}

	err = f.svc.writeProfilePassword(ctx, &user, hash, newHash)
	assertFieldError(t, err, "current_password", "mismatch")
}

func TestSetTemporaryPasswordForUnknownUser(t *testing.T) {
	t.Parallel()

	f := newFixture(t)

	err := f.svc.SetTemporaryPassword(context.Background(), "0190a1b2-0000-7000-8000-000000000000", testPassword)
	if !errors.Is(err, ErrUserNotFound) {
		t.Errorf("SetTemporaryPassword(unknown) = %v, want ErrUserNotFound", err)
	}
}

func TestResetPasswordValidatesItsInput(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	f.firstAdmin(t)
	ctx := context.Background()

	if err := f.svc.ResetPassword(ctx, "", testPassword); !errors.Is(err, ErrResetTokenInvalid) {
		t.Errorf("ResetPassword(empty token) = %v, want ErrResetTokenInvalid", err)
	}
	if err := f.svc.ResetPassword(ctx, "made-up", testPassword); !errors.Is(err, ErrResetTokenInvalid) {
		t.Errorf("ResetPassword(unknown token) = %v, want ErrResetTokenInvalid", err)
	}
	assertFieldError(t, f.svc.ResetPassword(ctx, "made-up", "short"), "password", "min")
}

func TestAccountWritesFailOnACancelledContext(t *testing.T) {
	t.Parallel()

	f := newFixture(t)
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	in := CreateUserInput{Name: "Marcelo", Email: testEmail, Password: testPassword}

	if _, err := f.svc.FirstAdmin(ctx, in); err == nil {
		t.Error("FirstAdmin on a cancelled context = nil error, want failure")
	}
	if _, err := f.svc.CreateUser(ctx, in); err == nil {
		t.Error("CreateUser on a cancelled context = nil error, want failure")
	}
	if err := f.svc.SetTemporaryPassword(ctx, "0190a1b2-0000-7000-8000-000000000000", testPassword); err == nil {
		t.Error("SetTemporaryPassword on a cancelled context = nil error, want failure")
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
