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
/** Dia (AAAA-MM-DD) de uma data de refeição gravada no horário do consultório, sem converter fuso. */
export function localDayKey(value?: string | null): string | null {
  const m = typeof value === 'string' ? value.match(/^(\d{4}-\d{2}-\d{2})/) : null;
  return m ? m[1] : null;
}
/** Hoje no aparelho, no mesmo formato de localDayKey. */
export function todayDayKey(now: Date = new Date()): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}`;
}
/** A refeição foi registrada hoje? Compara o texto do dia, sem passar por Date (que mudaria o dia por causa do fuso). */
export function isMealToday(value?: string | null, now: Date = new Date()): boolean {
  return localDayKey(value) === todayDayKey(now);
}
const WEEKDAYS = ['dom.', 'seg.', 'ter.', 'qua.', 'qui.', 'sex.', 'sáb.'];
const WEEKDAYS_LONG = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
const ISO_RE = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::\d{2})?([+-])(\d{2}):(\d{2})$/;
/**
 * NI2-137: data da consulta no horário do consultório, a partir do ISO com offset que a API envia
 * ("2026-10-28T14:00:00-03:00" → "qua., 28/10 às 14:00"). Não converte para o fuso do aparelho.
 */
export function formatClinicDateTime(iso?: string | null, opts: { long?: boolean; withTime?: boolean } = {}): string {
  const m = typeof iso === 'string' ? iso.match(ISO_RE) : null;
  if (!m) return 'Data não informada';
  const weekday = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))).getUTCDay();
  const day = `${opts.long ? WEEKDAYS_LONG[weekday] : WEEKDAYS[weekday]}, ${m[3]}/${m[2]}`;
  return opts.withTime === false ? day : `${day} às ${m[4]}:${m[5]}`;
}
export function formatClinicTime(iso?: string | null): string {
  const m = typeof iso === 'string' ? iso.match(ISO_RE) : null;
  return m ? `${m[4]}:${m[5]}` : '';
}
/** O aparelho está em outro fuso que o consultório naquele instante? (para avisar a paciente) */
export function deviceTimezoneDiffers(iso?: string | null): boolean {
  const m = typeof iso === 'string' ? iso.match(ISO_RE) : null;
  if (!m) return false;
  const clinicOffset = (m[6] === '-' ? -1 : 1) * (Number(m[7]) * 60 + Number(m[8]));
  const instant = new Date(iso as string);
  return !Number.isNaN(instant.getTime()) && -instant.getTimezoneOffset() !== clinicOffset;
}
export const tabLabels: Record<string, string> = { Hoje: 'Hoje', Plano: 'Plano', Diario: 'Diário', Chat: 'Chat', Evolucao: 'Evolução', Perfil: 'Perfil' };
