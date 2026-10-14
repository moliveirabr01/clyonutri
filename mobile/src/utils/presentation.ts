// Backend timestamps without an offset are UTC (SQLite CURRENT_TIMESTAMP).
export function displayDateTime(value?: string | null): string {
  if (!value) return 'Data não informada';
  const normalized = value.replace(' ', 'T');
  const date = new Date(/[zZ]$|[+-]\d\d:\d\d$/.test(normalized) ? normalized : `${normalized}Z`);
  return Number.isNaN(date.getTime()) ? 'Data não informada' : date.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}
export const tabLabels: Record<string, string> = { Hoje: 'Hoje', Plano: 'Plano', Diario: 'Diário', Chat: 'Chat', Evolucao: 'Evolução', Perfil: 'Perfil' };
