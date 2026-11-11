/**
 * NI2-92 (A2): histórico de água da paciente, por dia, com correção e exclusão
 * dos registros ainda editáveis (feitos no app há menos de 24 h).
 * Sem Alert.alert (não aparece no navegador): confirmações e erros ficam na própria tela.
 */
import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { ApiError, ApiService, HydrationEntry, HydrationHistory as History, HydrationHistoryDay, HydrationResult } from '../services/api';
import { patientTokens as t } from '../theme/patient';

const TZ = 'America/Sao_Paulo';
const fmtDay = (d: string) => {
  const [y, m, dd] = d.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, dd, 12)).toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit', timeZone: 'UTC' });
};
const fmtTime = (iso: string) => new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: TZ });
const msg = (e: unknown) => e instanceof ApiError ? e.message + (e.requestId ? ` Código de atendimento: ${e.requestId}.` : '') : 'Não foi possível concluir. Tente novamente.';

const btn = { minHeight: 44, minWidth: 44, justifyContent: 'center' as const, paddingHorizontal: 10 };

export default function HydrationHistory({ refreshToken, onChanged }: { refreshToken: number; onChanged: (r: HydrationResult) => void }) {
  const [days, setDays] = useState<HydrationHistoryDay[]>([]);
  const [meta, setMeta] = useState<Pick<History, 'next_before' | 'edit_window_hours'> | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [editing, setEditing] = useState<{ id: number; value: string } | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<number | null>(null);
  const [busy, setBusy] = useState<number | null>(null);

  const load = useCallback(async (before: string | null = null) => {
    setLoading(true); setError(null);
    try {
      const h = await ApiService.getHydrationHistory(before, 7);
      setDays((prev) => before ? [...prev, ...h.days] : h.days);
      setMeta({ next_before: h.next_before, edit_window_hours: h.edit_window_hours });
    } catch (e) { setError(msg(e)); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { load(null); }, [load, refreshToken]);

  const save = async (entry: HydrationEntry) => {
    if (!editing) return;
    const v = editing.value.trim();
    if (!/^\d{1,4}$/.test(v) || Number(v) < 1 || Number(v) > 5000) { setError('Informe um número inteiro de 1 a 5000 ml.'); return; }
    setBusy(entry.id); setError(null);
    try {
      const r = await ApiService.updateHydration(entry.id, { amount_ml: Number(v) });
      setEditing(null); setNotice(`Registro das ${fmtTime(entry.logged_at)} corrigido para ${r.amount_ml} ml.`);
      onChanged(r); await load(null);
    } catch (e) { setError(msg(e)); }
    finally { setBusy(null); }
  };

  const remove = async (entry: HydrationEntry) => {
    setBusy(entry.id); setError(null);
    try {
      const r = await ApiService.deleteHydration(entry.id);
      setConfirmDelete(null); setNotice(`Registro de ${entry.amount_ml} ml das ${fmtTime(entry.logged_at)} excluído.`);
      onChanged(r); await load(null);
    } catch (e) { setError(msg(e)); }
    finally { setBusy(null); }
  };

  return (
    <View testID="hydration-history" style={{ marginTop: 12 }}>
      <Text accessibilityRole="header" style={{ fontWeight: '700', color: t.navy, marginBottom: 4 }}>Histórico de água</Text>
      {meta && <Text style={{ color: t.muted, fontSize: 12, marginBottom: 8 }}>Você pode corrigir ou excluir um registro em até {meta.edit_window_hours} h depois de feito.</Text>}
      {notice && <Text accessibilityLiveRegion="polite" style={{ color: t.success, marginBottom: 8 }}>{notice}</Text>}
      {error && <Text accessibilityRole="alert" accessibilityLiveRegion="assertive" style={{ color: t.error, marginBottom: 8 }}>{error}</Text>}
      {!loading && days.length === 0 && !error && <Text style={{ color: t.muted }}>Nenhum registro de água nos últimos dias.</Text>}
      {days.map((d) => (
        <View key={d.date} style={{ marginBottom: 10 }}>
          <Text style={{ fontWeight: '600', color: t.ink }}>
            {fmtDay(d.date)} — {d.total_ml.toLocaleString('pt-BR')} ml{d.goal_reached ? ' (meta atingida)' : ''}
          </Text>
          {d.entries.map((e) => (
            <View key={e.id} style={{ flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 4 }}>
              <Text style={{ color: t.ink, minWidth: 110 }}>{fmtTime(e.logged_at)} · {e.amount_ml.toLocaleString('pt-BR')} ml{e.updated_at ? ' (corrigido)' : ''}</Text>
              {e.editable && editing?.id !== e.id && confirmDelete !== e.id && (
                <>
                  <TouchableOpacity style={btn} accessibilityRole="button" accessibilityLabel={`Corrigir registro de ${e.amount_ml} ml das ${fmtTime(e.logged_at)}`} onPress={() => { setEditing({ id: e.id, value: String(e.amount_ml) }); setConfirmDelete(null); setNotice(null); }}>
                    <Text style={{ color: t.navy, textDecorationLine: 'underline' }}>Corrigir</Text>
                  </TouchableOpacity>
                  <TouchableOpacity style={btn} accessibilityRole="button" accessibilityLabel={`Excluir registro de ${e.amount_ml} ml das ${fmtTime(e.logged_at)}`} onPress={() => { setConfirmDelete(e.id); setEditing(null); setNotice(null); }}>
                    <Text style={{ color: t.error, textDecorationLine: 'underline' }}>Excluir</Text>
                  </TouchableOpacity>
                </>
              )}
              {editing?.id === e.id && (
                <>
                  <TextInput
                    value={editing.value}
                    onChangeText={(value) => setEditing({ id: e.id, value: value.replace(/\D/g, '').slice(0, 4) })}
                    keyboardType="number-pad"
                    accessibilityLabel="Novo volume em mililitros"
                    style={{ borderWidth: 1, borderColor: t.inputBorder, borderRadius: t.inputRadius, minHeight: 44, minWidth: 80, paddingHorizontal: 8 }}
                  />
                  <TouchableOpacity style={btn} accessibilityRole="button" disabled={busy === e.id} accessibilityState={{ busy: busy === e.id, disabled: busy === e.id }} onPress={() => save(e)}>
                    <Text style={{ color: t.navy, fontWeight: '600' }}>Salvar</Text>
                  </TouchableOpacity>
                  <TouchableOpacity style={btn} accessibilityRole="button" onPress={() => setEditing(null)}>
                    <Text style={{ color: t.muted }}>Cancelar</Text>
                  </TouchableOpacity>
                </>
              )}
              {confirmDelete === e.id && (
                <>
                  <Text style={{ color: t.ink }}>Excluir este registro?</Text>
                  <TouchableOpacity style={btn} accessibilityRole="button" accessibilityLabel={`Confirmar exclusão do registro de ${e.amount_ml} ml`} disabled={busy === e.id} accessibilityState={{ busy: busy === e.id, disabled: busy === e.id }} onPress={() => remove(e)}>
                    <Text style={{ color: t.error, fontWeight: '600' }}>Sim, excluir</Text>
                  </TouchableOpacity>
                  <TouchableOpacity style={btn} accessibilityRole="button" onPress={() => setConfirmDelete(null)}>
                    <Text style={{ color: t.muted }}>Manter</Text>
                  </TouchableOpacity>
                </>
              )}
            </View>
          ))}
        </View>
      ))}
      {loading && <ActivityIndicator accessibilityLabel="Carregando histórico de água" />}
      {!loading && meta?.next_before && (
        <TouchableOpacity style={btn} accessibilityRole="button" onPress={() => load(meta.next_before)}>
          <Text style={{ color: t.navy, textDecorationLine: 'underline' }}>Ver dias anteriores</Text>
        </TouchableOpacity>
      )}
    </View>
  );
}
