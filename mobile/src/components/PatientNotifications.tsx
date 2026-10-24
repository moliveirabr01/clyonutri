import React, { useCallback, useEffect, useState } from 'react';
import { StyleSheet, Switch, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { ApiError, ApiService, NotificationPreferences, PatientNotification } from '../services/api';
import { displayLocalDateTime } from '../utils/presentation';
import { tokens as t } from '../theme/tokens';

const HHMM = /^([01]\d|2[0-3]):[0-5]\d$/;

function errorText(error: unknown, fallback: string): string {
  return error instanceof ApiError && error.message ? error.message : fallback;
}

/**
 * NI2-95: avisos da paciente (plano novo, lembrete de consulta, mensagem, lembrete de check-in) e o que ela quer receber por e-mail
 * e no celular. As chaves de push só aparecem quando o servidor está com o push ligado (push_available).
 */
export default function PatientNotifications({ active }: { active: boolean }) {
  const [items, setItems] = useState<PatientNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const [nextBefore, setNextBefore] = useState<number | null>(null);
  const [prefs, setPrefs] = useState<NotificationPreferences | null>(null);
  const [quietStart, setQuietStart] = useState('22:00');
  const [quietEnd, setQuietEnd] = useState('07:00');
  const [checkinTime, setCheckinTime] = useState('20:00');
  const [error, setError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setError(null);
    try {
      const [inbox, p] = await Promise.all([ApiService.getNotifications(), ApiService.getNotificationPreferences()]);
      setItems(inbox.items); setUnread(inbox.unread_count); setNextBefore(inbox.next_before_id);
      setPrefs(p); setQuietStart(p.quiet_hours.start); setQuietEnd(p.quiet_hours.end);
      if (p.checkin_reminder) setCheckinTime(p.checkin_reminder.time);
    } catch (e) {
      setError(errorText(e, 'Não foi possível carregar seus avisos. Tente novamente.'));
    }
  }, []);
  useEffect(() => { if (active) { void load(); } }, [active, load]);

  const more = async () => {
    if (!nextBefore) return;
    try {
      const page = await ApiService.getNotifications(nextBefore);
      setItems((cur) => [...cur, ...page.items]); setNextBefore(page.next_before_id); setUnread(page.unread_count);
    } catch (e) { setError(errorText(e, 'Não foi possível carregar mais avisos.')); }
  };
  const markRead = async (n: PatientNotification) => {
    if (n.read) return;
    try {
      const r = await ApiService.markNotificationRead(n.id);
      setItems((cur) => cur.map((x) => (x.id === n.id ? { ...x, read: true } : x))); setUnread(r.unread_count);
    } catch (e) { setError(errorText(e, 'Não foi possível marcar o aviso como lido.')); }
  };
  const markAll = async () => {
    try {
      const r = await ApiService.markAllNotificationsRead();
      setItems((cur) => cur.map((x) => ({ ...x, read: true }))); setUnread(r.unread_count);
    } catch (e) { setError(errorText(e, 'Não foi possível marcar os avisos como lidos.')); }
  };
  const save = async (update: Parameters<typeof ApiService.saveNotificationPreferences>[0], ok: string) => {
    setBusy(true); setFeedback(null); setError(null);
    try {
      const p = await ApiService.saveNotificationPreferences(update);
      setPrefs(p); setQuietStart(p.quiet_hours.start); setQuietEnd(p.quiet_hours.end); setFeedback(ok);
      if (p.checkin_reminder) setCheckinTime(p.checkin_reminder.time);
    } catch (e) {
      setError(errorText(e, 'Não foi possível salvar. Verifique sua conexão e tente de novo.'));
    } finally { setBusy(false); }
  };
  const saveQuiet = () => {
    if (!HHMM.test(quietStart) || !HHMM.test(quietEnd)) { setError('Use o formato HH:MM, por exemplo 22:00.'); return; }
    void save({ quiet_hours: { start: quietStart, end: quietEnd } }, 'Horário de silêncio salvo.');
  };
  const saveCheckinTime = () => {
    if (!HHMM.test(checkinTime)) { setError('Use o formato HH:MM, por exemplo 20:00.'); return; }
    void save({ checkin_reminder: { time: checkinTime } }, `Lembrete de check-in às ${checkinTime}.`);
  };

  return (
    <View>
      <View style={s.card}>
        <View style={s.row}>
          <Text style={s.title} accessibilityRole="header">Avisos{unread > 0 ? ` (${unread} novo${unread > 1 ? 's' : ''})` : ''}</Text>
          {unread > 0 && (
            <TouchableOpacity accessibilityRole="button" onPress={markAll}><Text style={s.link}>Marcar todos como lidos</Text></TouchableOpacity>
          )}
        </View>
        {error && <Text accessibilityRole="alert" style={s.error}>{error}</Text>}
        {!error && items.length === 0 && <Text style={s.helper}>Você não tem avisos por enquanto.</Text>}
        {items.map((n) => (
          <TouchableOpacity key={n.id} accessibilityRole="button" accessibilityLabel={`${n.read ? '' : 'Não lido. '}${n.title}. ${n.body}`}
            onPress={() => markRead(n)} style={[s.item, !n.read && s.itemUnread]}>
            <Text style={[s.itemTitle, !n.read && { fontWeight: '700' }]}>{n.title}</Text>
            <Text style={s.body}>{n.body}</Text>
            <Text style={s.helper}>{displayLocalDateTime(n.created_at)}</Text>
          </TouchableOpacity>
        ))}
        {nextBefore && (
          <TouchableOpacity accessibilityRole="button" onPress={more} style={{ marginTop: 8 }}><Text style={s.link}>Ver avisos mais antigos</Text></TouchableOpacity>
        )}
      </View>

      {prefs && (
        <View style={s.card}>
          <Text style={s.title} accessibilityRole="header">{prefs.push_available ? 'O que receber por e-mail e no celular' : 'O que receber por e-mail'}</Text>
          <Text style={s.helper}>Os avisos sempre aparecem aqui no app. {prefs.push_available ? 'O e-mail e o aviso no celular não levam' : 'O e-mail não leva'} detalhes da sua saúde nem o texto das mensagens.</Text>
          {prefs.categories.map((c) => (
            <View key={c.category} style={s.row}>
              <Text style={s.body} nativeID={`pref-${c.category}`}>{c.label}</Text>
              <Switch accessibilityLabel={`E-mail: ${c.label}`} value={c.email_enabled} disabled={busy}
                onValueChange={(v) => save({ categories: { [c.category]: { email_enabled: v } } }, v ? `Você vai receber e-mail de: ${c.label.toLowerCase()}.` : `E-mail desligado: ${c.label.toLowerCase()}.`)} />
              {prefs.push_available && (
                <Switch accessibilityLabel={`Celular: ${c.label}`} value={c.push_enabled} disabled={busy}
                  onValueChange={(v) => save({ categories: { [c.category]: { push_enabled: v } } }, v ? `Aviso no celular ligado: ${c.label.toLowerCase()}.` : `Aviso no celular desligado: ${c.label.toLowerCase()}.`)} />
              )}
            </View>
          ))}
          {prefs.push_available
            ? <Text style={s.helper}>Primeira chave: e-mail. Segunda: celular. Se o celular não avisar, confira se as notificações do app estão permitidas nos ajustes do aparelho.</Text>
            : <Text style={s.helper}>Aviso no celular ainda não está disponível.</Text>}

          {prefs.checkin_reminder?.available && (
            <>
              <Text style={[s.title, { marginTop: 12 }]} accessibilityRole="header">Lembrete de check-in</Text>
              <View style={s.row}>
                <Text style={s.body}>Me lembrar se eu ainda não tiver feito o check-in do dia</Text>
                <Switch accessibilityLabel="Ligar lembrete de check-in" value={prefs.checkin_reminder.enabled} disabled={busy}
                  onValueChange={(v) => save({ checkin_reminder: { enabled: v, time: checkinTime } }, v ? `Lembrete de check-in ligado, às ${checkinTime}.` : 'Lembrete de check-in desligado.')} />
              </View>
              <View style={s.row}>
                <Text style={s.body}>Horário</Text>
                <TextInput accessibilityLabel="Horário do lembrete de check-in (HH:MM)" style={s.input} value={checkinTime} onChangeText={setCheckinTime} maxLength={5} keyboardType="numbers-and-punctuation" />
                <TouchableOpacity accessibilityRole="button" style={s.button} disabled={busy} onPress={saveCheckinTime}><Text style={s.buttonText}>Salvar</Text></TouchableOpacity>
              </View>
              <Text style={s.helper}>Vem desligado. É no máximo um lembrete por dia, e só se o check-in ainda estiver em aberto. Ele chega pelo canal ligado em “Check-in diário” acima.</Text>
            </>
          )}

          <Text style={[s.title, { marginTop: 12 }]} accessibilityRole="header">Horário de silêncio</Text>
          <View style={s.row}>
            <Text style={s.body}>{prefs.push_available ? 'Não mandar e-mail nem aviso no celular neste horário' : 'Não mandar e-mail neste horário'}</Text>
            <Switch accessibilityLabel="Ligar horário de silêncio" value={prefs.quiet_hours.enabled} disabled={busy}
              onValueChange={(v) => save({ quiet_hours: { enabled: v, start: quietStart, end: quietEnd } }, v ? 'Horário de silêncio ligado.' : 'Horário de silêncio desligado.')} />
          </View>
          <View style={s.row}>
            <TextInput accessibilityLabel="Início do silêncio (HH:MM)" style={s.input} value={quietStart} onChangeText={setQuietStart} maxLength={5} keyboardType="numbers-and-punctuation" />
            <Text style={s.body}>até</Text>
            <TextInput accessibilityLabel="Fim do silêncio (HH:MM)" style={s.input} value={quietEnd} onChangeText={setQuietEnd} maxLength={5} keyboardType="numbers-and-punctuation" />
            <TouchableOpacity accessibilityRole="button" style={s.button} disabled={busy} onPress={saveQuiet}><Text style={s.buttonText}>Salvar</Text></TouchableOpacity>
          </View>
          <Text style={s.helper}>Horário de Brasília. Lembretes de consulta que caírem no silêncio chegam quando ele acabar; mensagens novas e o lembrete de check-in ficam só aqui no app.</Text>
          {feedback && <Text accessibilityRole="alert" style={s.ok}>{feedback}</Text>}
        </View>
      )}
    </View>
  );
}

const s = StyleSheet.create({
  card: { backgroundColor: t.white, borderRadius: t.cardRadius, padding: t.space, marginBottom: t.space, borderWidth: 1, borderColor: t.border },
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, marginTop: 8, flexWrap: 'wrap' },
  title: { fontSize: 17, fontWeight: '700', color: t.navy },
  body: { fontSize: 15, color: t.ink, flexShrink: 1 },
  helper: { fontSize: 13, color: t.muted, marginTop: 4 },
  error: { color: t.error, marginTop: 8 },
  ok: { color: t.success, marginTop: 8 },
  link: { color: t.copper, fontWeight: '600', textDecorationLine: 'underline' },
  item: { paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: t.border },
  itemUnread: { borderLeftWidth: 3, borderLeftColor: t.copper, paddingLeft: 8 },
  itemTitle: { fontSize: 15, color: t.navy },
  input: { borderWidth: 1, borderColor: t.border, borderRadius: t.inputRadius, paddingHorizontal: 10, paddingVertical: 8, width: 72, color: t.ink, minHeight: 44 },
  button: { backgroundColor: t.navy, borderRadius: t.buttonRadius, paddingHorizontal: 14, paddingVertical: 10, minHeight: 44, justifyContent: 'center' },
  buttonText: { color: t.white, fontWeight: '700' },
});
