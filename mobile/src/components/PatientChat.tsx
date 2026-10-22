import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Linking, Platform, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import * as DocumentPicker from 'expo-document-picker';
import { ApiError, ApiService, ChatAccess, ChatAttachmentInput, ChatChannel, ChatMessage, ChatNotice, newChatKey } from '../services/api';
import { tokens as t } from '../theme/tokens';

const MAX_CHARS = 2000;
const MAX_FILE_BYTES = 10 * 1024 * 1024;
const STATUS_LABEL: Record<string, string> = { sent: 'Enviada', delivered: 'Entregue', read: 'Lida' };

type Pending = { key: string; text: string; attachment: ChatAttachmentInput | null; state: 'sending' | 'failed'; error?: string };

function errorText(error: unknown, fallback: string): string {
  if (error instanceof ApiError) return error.message + (error.requestId && !error.code ? ` Código de atendimento: ${error.requestId}.` : '');
  return fallback;
}

/** "14:05" hoje; "21/10 14:05" em outros dias (horário do consultório, vindo do ISO com offset). */
function when(iso?: string | null): string {
  const m = typeof iso === 'string' ? iso.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/) : null;
  if (!m) return '';
  const now = new Date();
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  return `${m[1]}-${m[2]}-${m[3]}` === today ? `${m[4]}:${m[5]}` : `${m[3]}/${m[2]} ${m[4]}:${m[5]}`;
}

/**
 * NI2-138: chat da paciente. Só abre com vínculo ativo (o servidor decide), mostra o tempo de resposta configurado
 * e o aviso de urgência, fica só para leitura quando o vínculo acaba, reenvia com a mesma chave (não duplica),
 * mostra "Enviando…"/"Não enviada", envia e abre anexos (PNG, JPG, PDF) e respeita o intervalo de atualização do servidor.
 */
export default function PatientChat({ active, draft, setDraft }: { active: boolean; draft: string; setDraft: (v: string) => void }) {
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [pending, setPending] = useState<Pending[]>([]);
  const [conversationId, setConversationId] = useState<number | null>(null);
  const [professional, setProfessional] = useState<{ id: number; name: string; crn?: string | null } | null>(null);
  const [channels, setChannels] = useState<ChatChannel[]>([]);
  const [access, setAccess] = useState<ChatAccess | null>(null);
  const [notice, setNotice] = useState<ChatNotice | null>(null);
  const [selectedProf, setSelectedProf] = useState<number | undefined>(undefined);
  const [attachment, setAttachment] = useState<ChatAttachmentInput | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [banner, setBanner] = useState<string | null>(null);
  const lastIdRef = useRef(0);
  const intervalRef = useRef(5000);
  const appActive = useRef(true);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const load = useCallback(async (incremental: boolean) => {
    try {
      const data = await ApiService.getChatMessages(incremental ? lastIdRef.current : undefined, selectedProf);
      setConversationId(data.conversation_id);
      setProfessional(data.professional ?? null);
      setChannels(data.channels ?? []);
      setAccess(data.access ?? null);
      if (data.notice) setNotice(data.notice);
      if (data.polling_policy) intervalRef.current = Math.max(3000, data.polling_policy.active_window_ms);
      const incoming = data.messages || [];
      if (!incremental) {
        setMessages(incoming);
      } else if (incoming.length) {
        setMessages((prev) => {
          const seen = new Set(prev.map((m) => m.id));
          return [...prev, ...incoming.filter((m) => !seen.has(m.id))];
        });
      }
      if (data.receipts && data.receipts.length) {
        const st = new Map(data.receipts.map((r) => [r.id, r.status]));
        setMessages((prev) => (prev.some((m) => st.has(m.id) && st.get(m.id) !== m.status)
          ? prev.map((m) => (st.has(m.id) ? { ...m, status: st.get(m.id) as ChatMessage['status'] } : m)) : prev));
      }
      if (incoming.length) lastIdRef.current = Math.max(lastIdRef.current, ...incoming.map((m) => m.id));
      else if (!incremental) lastIdRef.current = 0;
      if (data.conversation_id && incoming.some((m) => m.sender_type === 'professional' && m.status !== 'read')) {
        ApiService.markChatAsRead(data.conversation_id).catch(() => {});
      }
      setLoadError(null);
    } catch (error) {
      if (error instanceof ApiError && error.kind === 'auth') return;
      setLoadError(errorText(error, 'Não foi possível carregar as mensagens.'));
    } finally {
      setLoading(false);
    }
  }, [selectedProf]);

  // Atualização no intervalo que o servidor indicar; pausa com o app em segundo plano ou fora da aba.
  useEffect(() => {
    if (!active) return;
    let stopped = false;
    lastIdRef.current = 0;
    setLoading(true);
    const tick = async (incremental: boolean) => {
      if (stopped) return;
      if (appActive.current) await load(incremental);
      if (!stopped) timer.current = setTimeout(() => tick(true), intervalRef.current);
    };
    tick(false);
    const sub = AppState.addEventListener('change', (s) => { appActive.current = s === 'active'; });
    return () => { stopped = true; if (timer.current) clearTimeout(timer.current); sub.remove(); };
  }, [active, load]);

  const pickFile = async () => {
    setBanner(null);
    try {
      const res = await DocumentPicker.getDocumentAsync({ type: ['image/png', 'image/jpeg', 'application/pdf'], copyToCacheDirectory: true, multiple: false });
      if (res.canceled || !res.assets?.length) return;
      const a = res.assets[0];
      if (a.size && a.size > MAX_FILE_BYTES) { setBanner('O arquivo precisa ter até 10 MB.'); return; }
      setAttachment({ uri: a.uri, name: a.name || 'anexo', mimeType: a.mimeType || 'application/octet-stream', file: (a as { file?: Blob }).file ?? null });
    } catch {
      setBanner('Não foi possível abrir os arquivos do aparelho.');
    }
  };

  const deliver = async (p: Pending) => {
    setPending((list) => list.map((x) => (x.key === p.key ? { ...x, state: 'sending', error: undefined } : x)));
    try {
      const res = await ApiService.sendChatMessage(p.text, conversationId ?? undefined, p.key, p.attachment, selectedProf);
      setPending((list) => list.filter((x) => x.key !== p.key));
      setMessages((prev) => (prev.some((m) => m.id === res.message.id) ? prev : [...prev, res.message]));
      lastIdRef.current = Math.max(lastIdRef.current, res.message.id);
    } catch (error) {
      const msg = errorText(error, 'Sem conexão. A mensagem não foi enviada.');
      setPending((list) => list.map((x) => (x.key === p.key ? { ...x, state: 'failed', error: msg } : x)));
      if (error instanceof ApiError && (error.code === 'LINK_INACTIVE' || error.code === 'NO_ACTIVE_LINK')) load(false);
    }
  };

  const send = () => {
    const text = draft.trim();
    if ((!text && !attachment) || text.length > MAX_CHARS) return;
    const p: Pending = { key: newChatKey(), text, attachment, state: 'sending' };
    setPending((list) => [...list, p]);
    setDraft('');
    setAttachment(null);
    setBanner(null);
    deliver(p);
  };

  const openAttachment = async (id: number) => {
    setBanner(null);
    try {
      const url = await ApiService.getChatAttachmentUrl(id);
      if (Platform.OS === 'web' && typeof window !== 'undefined') window.open(url, '_blank', 'noopener');
      else await Linking.openURL(url);
    } catch (error) {
      setBanner(errorText(error, 'Não foi possível abrir o anexo.'));
    }
  };

  const canSend = !!access?.can_send;
  const tooLong = draft.length > MAX_CHARS;

  return (
    <View style={s.wrap} testID="patient-chat">
      <View style={s.header}>
        <Text style={s.title} accessibilityRole="header">{professional ? professional.name : 'Chat com o consultório'}</Text>
        {professional?.crn ? <Text style={s.muted}>{professional.crn}</Text> : null}
        <Text style={s.muted}>Mensagens com o seu nutricionista, sem horário marcado.</Text>
      </View>

      {channels.length > 1 && (
        <View style={s.row} accessibilityRole="radiogroup" accessibilityLabel="Profissional">
          {channels.map((c) => {
            const on = (selectedProf ?? professional?.id) === c.professional_id;
            return (
              <TouchableOpacity key={c.professional_id} onPress={() => setSelectedProf(c.professional_id)} style={[s.chip, on && s.chipOn]}
                accessibilityRole="radio" accessibilityState={{ checked: on }} aria-checked={on}>
                <Text style={on ? s.chipTextOn : s.chipText}>{c.professional_name}{c.unread_count ? ` (${c.unread_count})` : ''}</Text>
              </TouchableOpacity>
            );
          })}
        </View>
      )}

      {loadError && (
        <View style={s.errorBox} accessibilityRole="alert">
          <Text style={s.errorText}>{loadError}</Text>
          <TouchableOpacity onPress={() => { setLoading(true); load(false); }} accessibilityRole="button" style={s.linkBtn}><Text style={s.link}>Tentar de novo</Text></TouchableOpacity>
        </View>
      )}
      {banner && <Text style={s.errorText} accessibilityRole="alert">{banner}</Text>}
      {loading && messages.length === 0 && <ActivityIndicator accessibilityLabel="Carregando mensagens" style={{ marginVertical: 24 }} />}

      <View style={s.list}>
        {!loading && conversationId === null && access?.code === 'NO_ACTIVE_LINK' && (
          <Text style={s.empty} testID="chat-no-link">{access.reason}</Text>
        )}
        {!loading && conversationId !== null && messages.length === 0 && pending.length === 0 && (
          <Text style={s.empty}>Mande dúvidas sobre o plano e o seu acompanhamento.</Text>
        )}
        {messages.map((m) => {
          const mine = m.sender_type === 'patient';
          return (
            <View key={m.id} style={[s.bubble, mine ? s.mine : s.theirs]} accessible
              accessibilityLabel={`${mine ? 'Você' : professional?.name || 'Nutricionista'}: ${m.message}${m.attachment ? `, anexo ${m.attachment.name}` : ''}. ${when(m.created_at)}${mine ? `, ${STATUS_LABEL[m.status] || 'Enviada'}` : ''}`}>
              {!!m.message && <Text style={mine ? s.textMine : s.text}>{m.message}</Text>}
              {m.attachment && (
                <TouchableOpacity onPress={() => openAttachment(m.attachment!.id)} accessibilityRole="link" accessibilityLabel={`Abrir anexo ${m.attachment.name}`} style={s.attach}>
                  <Text style={mine ? s.attachTextMine : s.attachText}>Anexo: {m.attachment.name}</Text>
                </TouchableOpacity>
              )}
              <Text style={mine ? s.metaMine : s.meta}>{when(m.created_at)}{mine ? ` · ${STATUS_LABEL[m.status] || 'Enviada'}` : ''}</Text>
            </View>
          );
        })}
        {pending.map((p) => (
          <View key={p.key} style={[s.bubble, s.mine, p.state === 'failed' && s.failed]} testID={`pending-${p.state}`}>
            {!!p.text && <Text style={s.textMine}>{p.text}</Text>}
            {p.attachment && <Text style={s.attachTextMine}>Anexo: {p.attachment.name}</Text>}
            <Text style={s.metaMine}>{p.state === 'sending' ? 'Enviando…' : 'Não enviada'}</Text>
            {p.state === 'failed' && (
              <>
                {p.error ? <Text style={s.failedText} accessibilityRole="alert">{p.error}</Text> : null}
                <View style={s.row}>
                  <TouchableOpacity onPress={() => deliver(p)} accessibilityRole="button" style={s.smallBtn}><Text style={s.smallBtnText}>Tentar de novo</Text></TouchableOpacity>
                  <TouchableOpacity onPress={() => setPending((l) => l.filter((x) => x.key !== p.key))} accessibilityRole="button" style={s.smallBtn}><Text style={s.smallBtnText}>Descartar</Text></TouchableOpacity>
                </View>
              </>
            )}
          </View>
        ))}
      </View>

      {conversationId !== null && !canSend && access?.reason ? (
        <Text style={s.readOnly} accessibilityRole="text" testID="chat-read-only">{access.reason}</Text>
      ) : null}

      {canSend && (
        <View style={s.composer}>
          {notice && (
            <View style={s.notice} accessibilityRole="text" testID="chat-notice">
              <Text style={s.noticeText}>{notice.response_time_text}</Text>
              <Text style={s.noticeStrong}>{notice.emergency_text}</Text>
            </View>
          )}
          {attachment && (
            <View style={s.row}>
              <Text style={s.muted}>Anexo: {attachment.name}</Text>
              <TouchableOpacity onPress={() => setAttachment(null)} accessibilityRole="button" accessibilityLabel="Remover anexo" style={s.linkBtn}><Text style={s.link}>Remover</Text></TouchableOpacity>
            </View>
          )}
          <TextInput value={draft} onChangeText={setDraft} multiline maxLength={MAX_CHARS + 1} style={s.input}
            placeholder="Escreva sua mensagem" placeholderTextColor="#6B7280" accessibilityLabel="Mensagem para o nutricionista" />
          {tooLong && <Text style={s.errorText} accessibilityRole="alert">A mensagem pode ter até 2.000 caracteres.</Text>}
          <View style={s.row}>
            <TouchableOpacity onPress={pickFile} accessibilityRole="button" accessibilityLabel="Anexar arquivo (PNG, JPG ou PDF, até 10 MB)" style={s.secondaryBtn}>
              <Text style={s.secondaryText}>Anexar</Text>
            </TouchableOpacity>
            <TouchableOpacity onPress={send} disabled={(!draft.trim() && !attachment) || tooLong} accessibilityRole="button" accessibilityLabel="Enviar mensagem"
              accessibilityState={{ disabled: (!draft.trim() && !attachment) || tooLong }} style={[s.primaryBtn, ((!draft.trim() && !attachment) || tooLong) && s.disabled]}>
              <Text style={s.primaryText}>Enviar</Text>
            </TouchableOpacity>
          </View>
        </View>
      )}
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { paddingBottom: 24 },
  header: { backgroundColor: t.white, borderRadius: 14, padding: 16, marginBottom: 12, borderWidth: 1, borderColor: '#E5E7EB' },
  title: { fontSize: 17, fontWeight: '700', color: t.navy },
  muted: { fontSize: 13, color: '#4B5563', marginTop: 4 },
  row: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', marginTop: 8 },
  chip: { borderWidth: 1, borderColor: t.navy, borderRadius: 16, paddingHorizontal: 12, minHeight: 40, justifyContent: 'center', marginRight: 8, marginBottom: 8 },
  chipOn: { backgroundColor: t.navy },
  chipText: { color: t.navy, fontWeight: '600' },
  chipTextOn: { color: t.white, fontWeight: '700' },
  list: { backgroundColor: t.white, borderRadius: 14, padding: 12, borderWidth: 1, borderColor: '#E5E7EB', minHeight: 160 },
  empty: { color: '#4B5563', fontSize: 14, textAlign: 'center', marginVertical: 24 },
  bubble: { maxWidth: '85%', borderRadius: 12, padding: 10, marginVertical: 4 },
  mine: { alignSelf: 'flex-end', backgroundColor: t.navy },
  theirs: { alignSelf: 'flex-start', backgroundColor: '#F1F5F9' },
  failed: { backgroundColor: '#7A271A' },
  text: { color: t.ink, fontSize: 15 },
  textMine: { color: t.white, fontSize: 15 },
  meta: { color: '#475569', fontSize: 12, marginTop: 4 },
  metaMine: { color: '#E2E8F0', fontSize: 12, marginTop: 4 },
  failedText: { color: '#FEE4E2', fontSize: 13, marginTop: 4 },
  attach: { minHeight: 44, justifyContent: 'center' },
  attachText: { color: t.navy, textDecorationLine: 'underline', fontWeight: '600' },
  attachTextMine: { color: t.white, textDecorationLine: 'underline', fontWeight: '600', marginTop: 4 },
  composer: { marginTop: 12 },
  notice: { backgroundColor: '#FFF7ED', borderLeftWidth: 4, borderLeftColor: '#B45309', padding: 10, borderRadius: 8, marginBottom: 8 },
  noticeText: { color: t.ink, fontSize: 13 },
  noticeStrong: { color: '#7C2D12', fontSize: 13, fontWeight: '700', marginTop: 4 },
  readOnly: { marginTop: 12, padding: 12, borderRadius: 10, backgroundColor: '#FFF7ED', color: '#7C2D12' },
  input: { borderWidth: 1, borderColor: t.border, borderRadius: t.inputRadius, padding: 10, minHeight: 64, backgroundColor: t.white, color: t.ink, fontSize: 15 },
  primaryBtn: { backgroundColor: t.copper, borderRadius: t.buttonRadius, minHeight: 44, paddingHorizontal: 20, justifyContent: 'center', marginLeft: 8 },
  primaryText: { color: t.white, fontWeight: '700' },
  secondaryBtn: { borderWidth: 1, borderColor: t.border, borderRadius: t.buttonRadius, minHeight: 44, paddingHorizontal: 16, justifyContent: 'center' },
  secondaryText: { color: t.navy, fontWeight: '600' },
  smallBtn: { borderWidth: 1, borderColor: '#FECDCA', borderRadius: 8, minHeight: 36, paddingHorizontal: 10, justifyContent: 'center', marginRight: 8 },
  smallBtnText: { color: t.white, fontWeight: '600' },
  disabled: { opacity: 0.5 },
  errorBox: { backgroundColor: '#FEF3F2', padding: 10, borderRadius: 10, marginBottom: 8 },
  errorText: { color: t.error, fontSize: 13, marginTop: 4 },
  linkBtn: { minHeight: 44, justifyContent: 'center', marginLeft: 8 },
  link: { color: t.navy, textDecorationLine: 'underline', fontWeight: '600' },
});
