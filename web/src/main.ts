import { createApp } from 'vue';

import App from './App.vue';
import { i18n, syncDocumentLanguage } from './i18n';
import { router } from './router';
import './style.css';

syncDocumentLanguage();
createApp(App).use(i18n).use(router).mount('#app');
