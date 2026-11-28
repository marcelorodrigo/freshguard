import {
  createRouter,
  createWebHistory,
  type RouteRecordRaw,
  type Router,
  type RouterHistory,
} from 'vue-router';

import AppShell from './components/AppShell.vue';
import { resolveNavigation } from './navigation';
import { ensureSession, session } from './session';
import ChangePasswordView from './views/ChangePasswordView.vue';
import ItemFormView from './views/ItemFormView.vue';
import ItemsListView from './views/ItemsListView.vue';
import LocationsListView from './views/LocationsListView.vue';
import LoginView from './views/LoginView.vue';
import PasswordResetView from './views/PasswordResetView.vue';
import PlaceholderView from './views/PlaceholderView.vue';
import ProfileView from './views/ProfileView.vue';
import QuickConsumeView from './views/QuickConsumeView.vue';
import SetupView from './views/SetupView.vue';
import UsersListView from './views/UsersListView.vue';

const routes: RouteRecordRaw[] = [
  {
    path: '/setup',
    name: 'setup',
    component: SetupView,
    meta: { public: true },
  },
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { public: true },
  },
  {
    path: '/password-reset/:token',
    name: 'password-reset',
    component: PasswordResetView,
    meta: { public: true },
  },
  {
    path: '/change-password',
    name: 'change-password',
    component: ChangePasswordView,
    meta: { requiresAuth: true },
  },
  {
    path: '/',
    component: AppShell,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'dashboard',
        component: PlaceholderView,
      },
      {
        path: 'items',
        name: 'items',
        component: ItemsListView,
      },
      {
        path: 'items/new',
        name: 'item-new',
        component: ItemFormView,
      },
      {
        path: 'items/:id',
        name: 'item-edit',
        component: ItemFormView,
      },
      {
        path: 'quick-consume',
        name: 'quick-consume',
        component: QuickConsumeView,
      },
      {
        path: 'locations',
        name: 'locations',
        component: LocationsListView,
      },
      {
        path: 'users',
        name: 'users',
        component: UsersListView,
        meta: { adminOnly: true },
      },
      {
        path: 'profile',
        name: 'profile',
        component: ProfileView,
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/items',
  },
];

/** Builds the router with the auth gate; tests provide an in-memory history. */
export function createAppRouter(
  history: RouterHistory = createWebHistory(import.meta.env.BASE_URL),
): Router {
  const router = createRouter({ history, routes });

  router.beforeEach(async (to) => {
    await ensureSession();

    return resolveNavigation(
      {
        name: typeof to.name === 'string' ? to.name : null,
        fullPath: to.fullPath,
        query: to.query,
        requiresAuth: to.meta.requiresAuth === true,
        adminOnly: to.meta.adminOnly === true,
      },
      { setupRequired: session.setupRequired, user: session.user },
    );
  });

  return router;
}

export const router = createAppRouter();
