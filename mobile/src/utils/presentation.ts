// Backend timestamps without an offset are UTC (SQLite CURRENT_TIMESTAMP).
export function displayDateTime(value?: string | null): string {
  if (!value) return 'Data não informada';
  const normalized = value.replace(' ', 'T');
  const date = new Date(/[zZ]$|[+-]\d\d:\d\d$/.test(normalized) ? normalized : `${normalized}Z`);
  return Number.isNaN(date.getTime()) ? 'Data não informada' : date.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}
/** Data/hora gravada no fuso do consultório (sem fuso no texto): mostra como está, sem converter (NI2-135). */
export function displayLocalDateTime(value?: string | null, withTime = true): string {
  const m = typeof value === 'string' ? value.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/) : null;
  if (!m) return 'Data não informada';
  return `${m[3]}/${m[2]}/${m[1]}` + (withTime && m[4] ? ` ${m[4]}:${m[5]}` : '');
}
export const tabLabels: Record<string, string> = { Hoje: 'Hoje', Plano: 'Plano', Diario: 'Diário', Chat: 'Chat', Evolucao: 'Evolução', Perfil: 'Perfil' };
