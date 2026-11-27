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
import LoginView from './views/LoginView.vue';
import PlaceholderView from './views/PlaceholderView.vue';
import SetupView from './views/SetupView.vue';

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
        component: PlaceholderView,
      },
      {
        path: 'items/new',
        name: 'item-new',
        component: PlaceholderView,
      },
      {
        path: 'items/:id',
        name: 'item-edit',
        component: PlaceholderView,
      },
      {
        path: 'quick-consume',
        name: 'quick-consume',
        component: PlaceholderView,
      },
      {
        path: 'locations',
        name: 'locations',
        component: PlaceholderView,
      },
      {
        path: 'users',
        name: 'users',
        component: PlaceholderView,
        meta: { adminOnly: true },
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
