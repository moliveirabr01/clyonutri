import React, { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { ApiError, ApiService, DailyCheckin } from '../services/api';
import { patientTokens as t } from '../theme/patient';

const ADHERENCE = [
  { value: 0, label: 'Quase nada' },
  { value: 25, label: 'Pouco' },
  { value: 50, label: 'Metade' },
  { value: 75, label: 'Quase tudo' },
  { value: 100, label: 'Tudo' },
];
const LEVELS = [1, 2, 3, 4, 5];
const NOTES_MAX = 500;

/**
 * NI2-96: check-in do dia. Uma pergunta obrigatória (quanto do plano seguiu) e duas opcionais (energia e fome).
 * A observação vai só para o nutricionista. Um check-in por dia; dá para corrigir no mesmo dia.
 * Mensagens ficam dentro do card (funcionam no app e no portal web).
 */
export default function DailyCheckinCard({ checkin, onSaved }: { checkin: DailyCheckin | null | undefined; onSaved?: (c: DailyCheckin) => void }) {
  const [saved, setSaved] = useState<DailyCheckin | null>(checkin ?? null);
  const [editing, setEditing] = useState(!checkin);
  const [adherence, setAdherence] = useState<number | null>(checkin?.meal_adherence_pct ?? null);
  const [energy, setEnergy] = useState<number | null>(checkin?.energy_level ?? null);
  const [hunger, setHunger] = useState<number | null>(checkin?.hunger_level ?? null);
  const [notes, setNotes] = useState(checkin?.notes ?? '');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ kind: 'success' | 'error'; text: string } | null>(null);

  useEffect(() => {
    setSaved(checkin ?? null);
    if (checkin) {
      setEditing(false);
      setAdherence(checkin.meal_adherence_pct);
      setEnergy(checkin.energy_level);
      setHunger(checkin.hunger_level);
      setNotes(checkin.notes ?? '');
    }
  }, [checkin?.id, checkin?.meal_adherence_pct, checkin?.energy_level, checkin?.hunger_level, checkin?.notes]);

  const submit = async () => {
    if (busy) return;
    if (adherence === null) {
      setMessage({ kind: 'error', text: 'Escolha quanto do plano você seguiu hoje.' });
      return;
    }
    setBusy(true);
    setMessage(null);
    try {
      const res = await ApiService.submitCheckin({ meal_adherence_pct: adherence, energy_level: energy, hunger_level: hunger, notes: notes.trim() || null });
      setSaved(res.checkin);
      setEditing(false);
      setMessage({ kind: 'success', text: res.created ? 'Check-in de hoje enviado.' : 'Check-in de hoje corrigido.' });
      onSaved?.(res.checkin);
    } catch (e) {
      setMessage({ kind: 'error', text: e instanceof ApiError ? e.message : 'Não foi possível enviar o check-in. Tente de novo.' });
    } finally {
      setBusy(false);
    }
  };

  const chip = (selected: boolean, label: string, onPress: () => void, a11y: string, key: string | number) => (
    <TouchableOpacity key={key} style={[styles.chip, selected && styles.chipOn]} onPress={() => { if (message?.kind === 'error') setMessage(null); onPress(); }} disabled={busy}
      accessibilityRole="radio" aria-checked={selected} accessibilityState={{ checked: selected, disabled: busy }} accessibilityLabel={a11y}>
      <Text style={[styles.chipText, selected && styles.chipTextOn]}>{label}</Text>
    </TouchableOpacity>
  );

  const summary = saved ? ADHERENCE.find((a) => a.value === saved.meal_adherence_pct)?.label ?? `${saved.meal_adherence_pct}%` : null;

  return (
    <View style={styles.card} testID="daily-checkin-card">
      <Text style={styles.title} accessibilityRole="header">Check-in de hoje</Text>
      {!editing && saved ? (
        <>
          <Text style={styles.line}>Plano seguido hoje: <Text style={styles.strong}>{summary}</Text></Text>
          {saved.energy_level ? <Text style={styles.muted}>Energia: {saved.energy_level} de 5</Text> : null}
          {saved.hunger_level ? <Text style={styles.muted}>Fome: {saved.hunger_level} de 5</Text> : null}
          {saved.notes ? <Text style={styles.muted}>Observação: {saved.notes}</Text> : null}
          <TouchableOpacity style={styles.secondaryButton} onPress={() => { setEditing(true); setMessage(null); }} accessibilityRole="button">
            <Text style={styles.secondaryText}>Corrigir check-in de hoje</Text>
          </TouchableOpacity>
        </>
      ) : (
        <>
          <Text style={styles.label} nativeID="checkin-adherence">Quanto do plano você seguiu hoje?</Text>
          <View style={styles.row} accessibilityRole="radiogroup" accessibilityLabelledBy="checkin-adherence">
            {ADHERENCE.map((a) => chip(adherence === a.value, a.label, () => setAdherence(a.value), `${a.label} do plano`, a.value))}
          </View>
          <Text style={styles.label} nativeID="checkin-energy">Energia (opcional)</Text>
          <View style={styles.row} accessibilityRole="radiogroup" accessibilityLabelledBy="checkin-energy">
            {LEVELS.map((v) => chip(energy === v, String(v), () => setEnergy(energy === v ? null : v), `Energia ${v} de 5`, v))}
          </View>
          <Text style={styles.hint}>1 = muito baixa, 5 = muito alta</Text>
          <Text style={styles.label} nativeID="checkin-hunger">Fome (opcional)</Text>
          <View style={styles.row} accessibilityRole="radiogroup" accessibilityLabelledBy="checkin-hunger">
            {LEVELS.map((v) => chip(hunger === v, String(v), () => setHunger(hunger === v ? null : v), `Fome ${v} de 5`, v))}
          </View>
          <Text style={styles.hint}>1 = pouca, 5 = muita</Text>
          <Text style={styles.label} nativeID="checkin-notes">Observação para o nutricionista (opcional)</Text>
          <TextInput style={styles.input} value={notes} onChangeText={(v) => setNotes(v.slice(0, NOTES_MAX))} multiline maxLength={NOTES_MAX}
            accessibilityLabelledBy="checkin-notes" accessibilityLabel="Observação para o nutricionista" placeholder="Como foi o dia com o plano?" placeholderTextColor={t.muted} />
          <Text style={styles.hint}>{notes.length}/{NOTES_MAX}</Text>
          <TouchableOpacity style={[styles.primaryButton, busy && styles.disabled]} onPress={submit} disabled={busy} accessibilityRole="button" accessibilityState={{ disabled: busy, busy }}>
            {busy ? <ActivityIndicator color={t.white} /> : <Text style={styles.buttonText}>{saved ? 'Salvar correção' : 'Enviar check-in'}</Text>}
          </TouchableOpacity>
          {saved ? (
            <TouchableOpacity style={styles.linkButton} onPress={() => { setEditing(false); setMessage(null); }} accessibilityRole="button">
              <Text style={styles.linkText}>Cancelar</Text>
            </TouchableOpacity>
          ) : null}
        </>
      )}
      {message ? (
        <View style={[styles.banner, message.kind === 'success' ? styles.bannerSuccess : styles.bannerError]}
          accessibilityRole={message.kind === 'error' ? 'alert' : undefined} accessibilityLiveRegion={message.kind === 'error' ? 'assertive' : 'polite'}>
          <Text style={message.kind === 'success' ? styles.bannerSuccessText : styles.bannerErrorText}>{message.text}</Text>
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  card: { backgroundColor: t.white, borderRadius: t.cardRadius, padding: 16, marginBottom: 16, borderWidth: 1, borderColor: '#E5E7EB' },
  title: { fontSize: 16, fontWeight: '700', color: t.navy, marginBottom: 4 },
  line: { fontSize: 14, color: t.ink, marginTop: 6 },
  strong: { fontWeight: '700' },
  muted: { fontSize: 13, color: '#4B5563', marginTop: 4 },
  label: { fontSize: 14, fontWeight: '600', color: t.ink, marginTop: 12 },
  hint: { fontSize: 12, color: '#4B5563', marginTop: 2 },
  row: { flexDirection: 'row', flexWrap: 'wrap', marginTop: 6 },
  chip: { borderWidth: 1, borderColor: t.inputBorder, borderRadius: 22, minHeight: 44, minWidth: 44, paddingHorizontal: 14, justifyContent: 'center', alignItems: 'center', marginRight: 8, marginBottom: 8 },
  chipOn: { backgroundColor: t.navy, borderColor: t.navy },
  chipText: { color: t.ink, fontWeight: '600' },
  chipTextOn: { color: t.white, fontWeight: '700' },
  input: { borderWidth: 1, borderColor: t.inputBorder, borderRadius: t.inputRadius, padding: 10, marginTop: 4, minHeight: 64, color: t.ink, textAlignVertical: 'top' },
  primaryButton: { backgroundColor: t.copper, borderRadius: t.buttonRadius, minHeight: 44, justifyContent: 'center', alignItems: 'center', marginTop: 12, paddingHorizontal: 16 },
  secondaryButton: { borderWidth: 1, borderColor: t.inputBorder, borderRadius: t.buttonRadius, minHeight: 44, justifyContent: 'center', alignItems: 'center', marginTop: 12 },
  buttonText: { color: t.white, fontWeight: '700', fontSize: 14 },
  secondaryText: { color: t.navy, fontWeight: '600', fontSize: 14 },
  disabled: { opacity: 0.6 },
  linkButton: { minHeight: 44, justifyContent: 'center', alignItems: 'center', marginTop: 4 },
  linkText: { color: t.navy, fontWeight: '600', textDecorationLine: 'underline' },
  banner: { marginTop: 12, padding: 12, borderRadius: 10 },
  bannerSuccess: { backgroundColor: '#ECFDF3' },
  bannerError: { backgroundColor: '#FEF3F2' },
  bannerSuccessText: { color: '#05603A', fontWeight: '600' },
  bannerErrorText: { color: '#912018', fontWeight: '600' },
});
