import React, { useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { ApiError, ApiService, Appointment, RescheduleOptions } from '../services/api';
import { deviceTimezoneDiffers, formatClinicDateTime } from '../utils/presentation';
import { tokens as t } from '../theme/tokens';

type Banner = { kind: 'success' | 'error'; text: string } | null;
type Mode = 'idle' | 'cancel' | 'reschedule';

function errorText(error: unknown, fallback: string): string {
  // Recusa de regra (com código) já explica o que fazer; o código de atendimento só ajuda em falha do servidor.
  if (error instanceof ApiError) return error.message + (error.requestId && !error.code ? ` Código de atendimento: ${error.requestId}.` : '');
  return fallback;
}

/**
 * NI2-137: card da próxima consulta. Data no horário do consultório (com o fuso escrito), modalidade, local
 * ou sala online, e só as ações que o servidor libera em `allowed_actions`. Mensagens ficam dentro do card
 * (funcionam no app e no portal web). Depois de qualquer ação, a home é recarregada do servidor.
 */
export default function NextAppointmentCard({ appointment, onChanged, onOpenTelehealth }: {
  appointment: Appointment | null | undefined;
  onChanged: () => Promise<void> | void;
  onOpenTelehealth: (url: string) => void;
}) {
  const [busy, setBusy] = useState<null | 'confirm' | 'cancel' | 'options' | 'request' | 'withdraw'>(null);
  const [banner, setBanner] = useState<Banner>(null);
  const [mode, setMode] = useState<Mode>('idle');
  const [reason, setReason] = useState('');
  const [options, setOptions] = useState<RescheduleOptions | null>(null);
  const [selected, setSelected] = useState<string | null>(null);

  if (!appointment) {
    return (
      <View style={styles.card} accessibilityLabel="Próxima consulta: nenhuma consulta agendada">
        <Text style={styles.title} accessibilityRole="header">Próxima consulta</Text>
        <Text style={styles.muted}>Nenhuma consulta agendada no momento.</Text>
        {banner && <BannerView banner={banner} />}
      </View>
    );
  }

  const a = appointment;
  const iso = a.scheduled_at_iso ?? null;
  const when = iso ? formatClinicDateTime(iso) : a.scheduled_at;
  const tzLabel = a.timezone_label || 'horário do consultório';
  const actions = a.allowed_actions;
  const request = a.change_request ?? null;
  const pending = request?.status === 'pending' ? request : null;
  const isOnline = a.modality ? a.modality === 'online' : !!a.is_online;

  const reset = () => { setMode('idle'); setReason(''); setOptions(null); setSelected(null); };
  const run = async (kind: NonNullable<typeof busy>, fn: () => Promise<string>, fallback: string) => {
    if (busy) return;
    setBusy(kind);
    setBanner(null);
    try {
      const text = await fn();
      reset();
      setBanner({ kind: 'success', text });
    } catch (error) {
      setBanner({ kind: 'error', text: errorText(error, fallback) });
    } finally {
      setBusy(null);
      // O card sempre volta ao estado real do servidor (inclusive quando a ação foi recusada).
      try { await onChanged(); } catch { /* a home mostra o próprio erro */ }
    }
  };

  const confirm = () => run('confirm', async () => (await ApiService.confirmAppointment(a.id)).message || 'Presença confirmada.', 'Não foi possível confirmar. Tente novamente.');
  const cancel = () => run('cancel', async () => (await ApiService.cancelAppointment(a.id, reason.trim())).message || 'Consulta cancelada.', 'Não foi possível cancelar. Tente novamente.');
  const sendRequest = () => selected && run('request', async () => (await ApiService.requestReschedule(a.id, selected, reason.trim())).message || 'Pedido enviado.', 'Não foi possível enviar o pedido. Tente novamente.');
  const withdraw = () => pending && run('withdraw', async () => (await ApiService.withdrawRescheduleRequest(pending.id)).message || 'Pedido retirado.', 'Não foi possível retirar o pedido. Tente novamente.');
  const openReschedule = async () => {
    if (busy) return;
    setMode('reschedule'); setBanner(null); setSelected(null); setOptions(null); setBusy('options');
    try {
      setOptions(await ApiService.getRescheduleOptions(a.id));
    } catch (error) {
      setMode('idle');
      setBanner({ kind: 'error', text: errorText(error, 'Não foi possível carregar os horários livres. Tente novamente.') });
      try { await onChanged(); } catch { /* */ }
    } finally { setBusy(null); }
  };

  const blockedReasons = actions
    ? (['confirm', 'cancel', 'reschedule'] as const)
        .filter((k) => !actions[k].allowed && actions[k].reason && !(k === 'confirm' && a.status === 'confirmed'))
        .map((k) => actions[k].reason as string)
    : [];
  const uniqueReasons = blockedReasons.filter((r, i) => blockedReasons.indexOf(r) === i);

  return (
    <View style={styles.card} testID="next-appointment-card">
      <Text style={styles.title} accessibilityRole="header">Próxima consulta</Text>
      <Text style={styles.when} accessibilityLabel={`${iso ? formatClinicDateTime(iso, { long: true }) : when}, ${tzLabel}`}>{when}</Text>
      <Text style={styles.muted}>{tzLabel}{a.duration_minutes ? ` · ${a.duration_minutes} minutos` : ''}</Text>
      {iso && deviceTimezoneDiffers(iso) && (
        <Text style={styles.note}>Seu aparelho está em outro fuso. O horário acima é o do consultório.</Text>
      )}
      <Text style={styles.line}>{a.professional_name || 'Nutricionista'} · {a.status_label || (a.status === 'confirmed' ? 'Confirmada' : 'Agendada')}</Text>
      <Text style={styles.line}>{a.modality_label || (isOnline ? 'Teleconsulta (online)' : 'Presencial')}</Text>
      {!isOnline && a.location ? <Text style={styles.line}>Local: {a.location}</Text> : null}
      {isOnline && (a.meeting_url ? (
        <TouchableOpacity style={styles.onlineButton} onPress={() => onOpenTelehealth(a.meeting_url as string)} accessibilityRole="link" accessibilityLabel="Entrar na sala de espera da teleconsulta">
          <Text style={styles.buttonText}>Entrar na teleconsulta</Text>
        </TouchableOpacity>
      ) : a.video_status === 'aguardando_horario' && a.video_opens_at_iso ? (
        <Text style={styles.line}>A sala online abre {formatClinicDateTime(a.video_opens_at_iso)} ({tzLabel}). O botão de entrar aparece aqui.</Text>
      ) : (
        <Text style={styles.line}>A sala online ainda não foi aberta pelo consultório. Volte perto do horário.</Text>
      ))}

      {request && (
        <View style={[styles.requestBox, pending ? styles.requestPending : null]} accessibilityLabel={`Pedido de remarcação: ${request.status_label}`}>
          <Text style={styles.requestTitle}>Pedido de remarcação: {request.status_label.toLowerCase()}</Text>
          <Text style={styles.line}>Horário pedido: {formatClinicDateTime(request.requested_scheduled_at_iso)}</Text>
          {pending && <Text style={styles.muted}>Sua consulta continua no horário atual até o consultório responder.</Text>}
          {request.decision_note ? <Text style={styles.line}>Resposta: {request.decision_note}</Text> : null}
          {pending && (
            <TouchableOpacity style={styles.linkButton} onPress={withdraw} disabled={!!busy} accessibilityRole="button" accessibilityState={{ disabled: !!busy, busy: busy === 'withdraw' }}>
              <Text style={styles.linkText}>{busy === 'withdraw' ? 'Retirando…' : 'Desistir do pedido'}</Text>
            </TouchableOpacity>
          )}
        </View>
      )}

      {banner && <BannerView banner={banner} />}

      {mode === 'idle' && (
        <View>
          {actions?.confirm.allowed && (
            <TouchableOpacity style={[styles.primaryButton, busy ? styles.disabled : null]} onPress={confirm} disabled={!!busy} accessibilityRole="button" accessibilityLabel="Confirmar presença na próxima consulta" accessibilityState={{ disabled: !!busy, busy: busy === 'confirm' }}>
              {busy === 'confirm' ? <ActivityIndicator color={t.white} accessibilityLabel="Confirmando" /> : <Text style={styles.buttonText}>Confirmar presença</Text>}
            </TouchableOpacity>
          )}
          {actions?.reschedule.allowed && (
            <TouchableOpacity style={[styles.secondaryButton, busy ? styles.disabled : null]} onPress={openReschedule} disabled={!!busy} accessibilityRole="button" accessibilityState={{ disabled: !!busy }}>
              <Text style={styles.secondaryText}>Pedir outro horário</Text>
            </TouchableOpacity>
          )}
          {actions?.cancel.allowed && (
            <TouchableOpacity style={[styles.secondaryButton, busy ? styles.disabled : null]} onPress={() => { setBanner(null); setMode('cancel'); }} disabled={!!busy} accessibilityRole="button" accessibilityState={{ disabled: !!busy }}>
              <Text style={styles.dangerText}>Cancelar consulta</Text>
            </TouchableOpacity>
          )}
          {uniqueReasons.map((r) => <Text key={r} style={styles.muted}>{r}</Text>)}
          {a.clinic_phone && uniqueReasons.some((r) => r.includes('consultório')) ? <Text style={styles.muted}>Telefone do consultório: {a.clinic_phone}</Text> : null}
        </View>
      )}

      {mode === 'cancel' && (
        <View style={styles.panel} accessibilityLabel="Cancelar consulta">
          <Text style={styles.requestTitle}>Cancelar a consulta de {when}?</Text>
          <Text style={styles.muted}>O horário fica livre na agenda do consultório. Para voltar, será preciso marcar de novo.</Text>
          <Text style={styles.label} nativeID="cancel-reason-label">Motivo (opcional)</Text>
          <TextInput style={styles.input} value={reason} onChangeText={setReason} maxLength={300} accessibilityLabelledBy="cancel-reason-label" accessibilityLabel="Motivo do cancelamento (opcional)" placeholder="Ex.: imprevisto no trabalho" placeholderTextColor={t.muted} />
          <TouchableOpacity style={[styles.dangerButton, busy ? styles.disabled : null]} onPress={cancel} disabled={!!busy} accessibilityRole="button" accessibilityState={{ disabled: !!busy, busy: busy === 'cancel' }}>
            {busy === 'cancel' ? <ActivityIndicator color={t.white} accessibilityLabel="Cancelando" /> : <Text style={styles.buttonText}>Sim, cancelar consulta</Text>}
          </TouchableOpacity>
          <TouchableOpacity style={styles.linkButton} onPress={reset} disabled={!!busy} accessibilityRole="button"><Text style={styles.linkText}>Voltar sem cancelar</Text></TouchableOpacity>
        </View>
      )}

      {mode === 'reschedule' && (
        <View style={styles.panel} accessibilityLabel="Pedir outro horário">
          <Text style={styles.requestTitle}>Escolha um horário livre</Text>
          <Text style={styles.muted}>O consultório recebe seu pedido e aceita ou recusa. Até lá, a consulta continua em {when}.</Text>
          {busy === 'options' && <ActivityIndicator accessibilityLabel="Carregando horários livres" />}
          {options && options.days.length === 0 && <Text style={styles.line}>Não há horários livres nos próximos dias. Fale com o consultório{a.clinic_phone ? ` pelo telefone ${a.clinic_phone}` : ''}.</Text>}
          {options?.days.map((day) => (
            <View key={day.date} style={{ marginTop: 8 }}>
              <Text style={styles.dayLabel}>{formatClinicDateTime(day.slots[0]?.scheduled_at_iso, { withTime: false })}</Text>
              <View style={styles.slotRow} accessibilityRole="radiogroup" accessibilityLabel={`Horários de ${formatClinicDateTime(day.slots[0]?.scheduled_at_iso, { long: true, withTime: false })}`}>
                {day.slots.map((slot) => {
                  const on = selected === slot.scheduled_at;
                  return (
                    <TouchableOpacity key={slot.scheduled_at} style={[styles.slot, on ? styles.slotOn : null]} onPress={() => setSelected(slot.scheduled_at)}
                      accessibilityRole="radio" accessibilityState={{ checked: on }} aria-checked={on}
                      accessibilityLabel={`${formatClinicDateTime(slot.scheduled_at_iso, { long: true })}`}>
                      <Text style={on ? styles.slotTextOn : styles.slotText}>{slot.time_label}</Text>
                    </TouchableOpacity>
                  );
                })}
              </View>
            </View>
          ))}
          {options && options.days.length > 0 && (
            <>
              <Text style={styles.label} nativeID="reschedule-reason-label">Motivo (opcional)</Text>
              <TextInput style={styles.input} value={reason} onChangeText={setReason} maxLength={300} accessibilityLabelledBy="reschedule-reason-label" accessibilityLabel="Motivo do pedido (opcional)" placeholder="Ex.: viagem a trabalho" placeholderTextColor={t.muted} />
              <TouchableOpacity style={[styles.primaryButton, (!selected || busy) ? styles.disabled : null]} onPress={sendRequest} disabled={!selected || !!busy} accessibilityRole="button" accessibilityState={{ disabled: !selected || !!busy, busy: busy === 'request' }}>
                {busy === 'request' ? <ActivityIndicator color={t.white} accessibilityLabel="Enviando pedido" /> : <Text style={styles.buttonText}>{selected ? `Pedir ${formatClinicDateTime(options.days.flatMap((d) => d.slots).find((s) => s.scheduled_at === selected)?.scheduled_at_iso)}` : 'Escolha um horário'}</Text>}
              </TouchableOpacity>
            </>
          )}
          <TouchableOpacity style={styles.linkButton} onPress={reset} disabled={busy === 'request'} accessibilityRole="button"><Text style={styles.linkText}>Voltar</Text></TouchableOpacity>
        </View>
      )}
    </View>
  );
}

function BannerView({ banner }: { banner: NonNullable<Banner> }) {
  return (
    <View style={[styles.banner, banner.kind === 'error' ? styles.bannerError : styles.bannerSuccess]}
      accessibilityRole={banner.kind === 'error' ? 'alert' : 'text'} accessibilityLiveRegion={banner.kind === 'error' ? 'assertive' : 'polite'} testID="appointment-banner">
      <Text style={banner.kind === 'error' ? styles.bannerErrorText : styles.bannerSuccessText}>{banner.text}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  card: { backgroundColor: t.white, borderRadius: 14, padding: 16, marginBottom: 16, borderWidth: 1, borderColor: '#E5E7EB' },
  title: { fontSize: 16, fontWeight: '700', color: t.navy, marginBottom: 8 },
  when: { fontSize: 17, fontWeight: '700', color: t.navy },
  muted: { fontSize: 13, color: '#4B5563', marginTop: 4 },
  note: { fontSize: 13, color: '#92400E', marginTop: 4 },
  line: { fontSize: 14, color: t.ink, marginTop: 6 },
  label: { fontSize: 13, fontWeight: '600', color: t.ink, marginTop: 10 },
  input: { borderWidth: 1, borderColor: t.inputBorder, borderRadius: t.inputRadius, padding: 10, marginTop: 4, minHeight: 44, color: t.ink },
  primaryButton: { backgroundColor: t.copper, borderRadius: t.buttonRadius, minHeight: 44, justifyContent: 'center', alignItems: 'center', marginTop: 12, paddingHorizontal: 16 },
  onlineButton: { backgroundColor: '#027A48', borderRadius: t.buttonRadius, minHeight: 44, justifyContent: 'center', alignItems: 'center', marginTop: 10 },
  dangerButton: { backgroundColor: t.error, borderRadius: t.buttonRadius, minHeight: 44, justifyContent: 'center', alignItems: 'center', marginTop: 12 },
  secondaryButton: { borderWidth: 1, borderColor: t.inputBorder, borderRadius: t.buttonRadius, minHeight: 44, justifyContent: 'center', alignItems: 'center', marginTop: 8 },
  buttonText: { color: t.white, fontWeight: '700', fontSize: 14 },
  secondaryText: { color: t.navy, fontWeight: '600', fontSize: 14 },
  dangerText: { color: t.error, fontWeight: '600', fontSize: 14 },
  disabled: { opacity: 0.6 },
  linkButton: { minHeight: 44, justifyContent: 'center', marginTop: 4 },
  linkText: { color: t.navy, fontWeight: '600', textDecorationLine: 'underline' },
  panel: { marginTop: 12, borderTopWidth: 1, borderTopColor: '#E5E7EB', paddingTop: 12 },
  requestBox: { marginTop: 12, padding: 12, borderRadius: 10, backgroundColor: '#F3F4F6' },
  requestPending: { backgroundColor: '#FFF7ED', borderLeftWidth: 4, borderLeftColor: '#B45309' },
  requestTitle: { fontSize: 14, fontWeight: '700', color: t.ink },
  dayLabel: { fontSize: 13, fontWeight: '700', color: t.ink, marginTop: 4 },
  slotRow: { flexDirection: 'row', flexWrap: 'wrap', marginTop: 4 },
  slot: { borderWidth: 1, borderColor: t.inputBorder, borderRadius: 8, minHeight: 44, minWidth: 64, justifyContent: 'center', alignItems: 'center', marginRight: 8, marginBottom: 8, paddingHorizontal: 10 },
  slotOn: { backgroundColor: t.navy, borderColor: t.navy },
  slotText: { color: t.ink, fontWeight: '600' },
  slotTextOn: { color: t.white, fontWeight: '700' },
  banner: { marginTop: 12, padding: 12, borderRadius: 10 },
  bannerSuccess: { backgroundColor: '#ECFDF3' },
  bannerError: { backgroundColor: '#FEF3F2' },
  bannerSuccessText: { color: '#05603A', fontWeight: '600' },
  bannerErrorText: { color: '#912018', fontWeight: '600' },
});
