import type { MessageSchema } from './en';

/** pt-BR, typed against the English schema: same keys, no French. */
export const ptBR: MessageSchema = {
  app: {
    title: 'FreshGuard',
    tagline: 'Estoque da casa, um único binário.',
    loading: 'Consultando o servidor…',
    status: 'Status',
    version: 'Versão',
    serverTime: 'Hora do servidor',
  },
  errors: {
    title: 'Servidor inacessível',
    transport: 'O servidor não respondeu. Verifique a conexão e tente novamente.',
    not_found: 'Este servidor não expõe o endpoint de saúde.',
    unexpected: 'O servidor respondeu com um erro.',
  },
};
