import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { ApiError, ApiService, ShoppingListData } from '../services/api';
import { patientTokens as t } from '../theme/patient';
import { announce, radioKeyboardProps } from '../utils/a11y';

const PERIOD_OPTIONS = [7, 14, 30] as const;

const memoryCheckedStore = new Map<string, string[]>();

function readCheckedKeys(storageKey: string): string[] {
  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      const raw = window.localStorage.getItem(storageKey);
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed.filter((x): x is string => typeof x === 'string') : [];
    }
  } catch {
    // Ignore storage read errors and fall back to memory.
  }
  return memoryCheckedStore.get(storageKey) ?? [];
}

function writeCheckedKeys(storageKey: string, keys: string[]): void {
  memoryCheckedStore.set(storageKey, keys);
  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      window.localStorage.setItem(storageKey, JSON.stringify(keys));
    }
  } catch {
    // Ignore storage quota/privacy errors; memory state remains active.
  }
}

export default function PatientShoppingList({ patientId, active }: { patientId: number; active: boolean }) {
  const [days, setDays] = useState<number>(7);
  const [data, setData] = useState<ShoppingListData | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [noPlan, setNoPlan] = useState(false);
  const [checkedKeys, setCheckedKeys] = useState<string[]>([]);

  const storageKey = useMemo(
    () => (data ? `clyonutri_shopping_${patientId}_${data.plan_id}_${data.version}_${data.days}` : ''),
    [patientId, data],
  );

  const loadList = useCallback(async (selectedDays: number) => {
    setLoading(true);
    setError(null);
    setNoPlan(false);
    try {
      const result = await ApiService.getShoppingList(selectedDays);
      setData(result);
      const key = `clyonutri_shopping_${patientId}_${result.plan_id}_${result.version}_${result.days}`;
      const serverChecked = result.items.filter(it => it.is_checked).map(it => it.key);
      const localChecked = readCheckedKeys(key);
      const merged = Array.from(new Set([...serverChecked, ...localChecked]));
      setCheckedKeys(merged);
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) {
        setData(null);
        setNoPlan(true);
      } else {
        setError(e instanceof ApiError ? e.message : 'Não foi possível carregar a lista de compras. Tente novamente.');
      }
    } finally {
      setLoading(false);
    }
  }, [patientId]);

  useEffect(() => {
    if (active) {
      void loadList(days);
    }
  }, [active, days, loadList]);

  const toggleItem = (itemKey: string, itemName: string) => {
    if (!storageKey) return;
    setCheckedKeys(prev => {
      const exists = prev.includes(itemKey);
      const next = exists ? prev.filter(k => k !== itemKey) : [...prev, itemKey];
      writeCheckedKeys(storageKey, next);
      announce(exists ? `${itemName} desmarcado.` : `${itemName} marcado.`);
      return next;
    });
  };

  const clearChecked = () => {
    if (!storageKey) return;
    setCheckedKeys([]);
    writeCheckedKeys(storageKey, []);
    announce('Itens marcados limpos.');
  };

  const aisleEntries = useMemo(() => (data ? Object.entries(data.aisles || {}) : []), [data]);
  const totalCount = data?.items.length ?? 0;
  const checkedCount = useMemo(
    () => (data ? data.items.filter(it => checkedKeys.includes(it.key)).length : 0),
    [data, checkedKeys],
  );

  return (
    <View testID="patient-shopping-list" style={{ marginTop: 8 }}>
      <Text style={s.sectionTitle} accessibilityRole="header">Lista de compras</Text>
      <Text style={s.helperText}>
        Gerada automaticamente a partir do seu plano alimentar publicado.
      </Text>

      <Text style={s.periodLabel} nativeID="shopping-days-label">Período da lista</Text>
      <View style={s.periodRow} accessibilityRole="radiogroup" accessibilityLabelledBy="shopping-days-label">
        {PERIOD_OPTIONS.map(opt => {
          const selected = days === opt;
          return (
            <TouchableOpacity
              key={opt}
              {...radioKeyboardProps(() => setDays(opt), loading)}
              style={[s.periodChip, selected && s.periodChipActive]}
              onPress={() => setDays(opt)}
              disabled={loading}
              accessibilityRole="radio"
              aria-checked={selected}
              accessibilityState={{ checked: selected, disabled: loading }}
              accessibilityLabel={`${opt} dias`}
            >
              <Text style={[s.periodChipText, selected && s.periodChipTextActive]}>{opt} dias</Text>
            </TouchableOpacity>
          );
        })}
      </View>

      <View style={s.noticeRow}>
        {loading && <ActivityIndicator accessibilityLabel="Atualizando lista de compras" color={t.navy} />}
        {error && (
          <Text style={s.errorText} accessibilityRole="alert" accessibilityLiveRegion="assertive">
            {error}
          </Text>
        )}
        <TouchableOpacity
          accessibilityRole="button"
          accessibilityLabel={error ? 'Tentar novamente lista de compras' : 'Atualizar lista de compras'}
          onPress={() => void loadList(days)}
          disabled={loading}
          accessibilityState={{ busy: loading, disabled: loading }}
          style={s.refreshButton}
        >
          <Text style={s.refreshText}>{error ? 'Tentar novamente' : 'Atualizar'}</Text>
        </TouchableOpacity>
      </View>

      {noPlan && !loading && (
        <View style={s.card}>
          <Text style={s.cardTitle}>Lista indisponível no momento</Text>
          <Text style={s.helperText}>
            A lista de compras fica disponível assim que seu nutricionista publicar um plano alimentar ativo.
          </Text>
        </View>
      )}

      {data && !noPlan && (
        <>
          {data.allergens_and_restrictions?.has_conflicts && (
            <View style={s.warningBox} accessibilityRole="alert">
              <Text style={s.warningTitle}>Atenção a alergias e restrições</Text>
              <Text style={s.warningText}>
                Há {data.allergens_and_restrictions.conflicts_count} item(ns) com possível conflito cadastrado. Confira os avisos antes de comprar.
              </Text>
            </View>
          )}

          <View style={s.summaryRow}>
            <Text style={s.summaryText} accessibilityLiveRegion="polite">
              {checkedCount} de {totalCount} {totalCount === 1 ? 'item marcado' : 'itens marcados'} • {data.days} dias
            </Text>
            {checkedCount > 0 && (
              <TouchableOpacity
                accessibilityRole="button"
                onPress={clearChecked}
                style={s.clearButton}
              >
                <Text style={s.clearButtonText}>Limpar marcados</Text>
              </TouchableOpacity>
            )}
          </View>

          {aisleEntries.length === 0 ? (
            <View style={s.card}>
              <Text style={s.helperText}>Nenhum item encontrado para o plano publicado.</Text>
            </View>
          ) : (
            aisleEntries.map(([aisle, items]) => (
              <View key={aisle} style={s.card}>
                <Text style={s.cardTitle}>{aisle}</Text>
                {items.map(item => {
                  const isChecked = checkedKeys.includes(item.key);
                  const qtyText = `${Number(item.amount).toLocaleString('pt-BR')} ${item.unit}`;
                  const sourceText = item.meals_source?.length ? item.meals_source.join(', ') : '';
                  return (
                    <TouchableOpacity
                      key={item.key}
                      style={[s.itemRow, isChecked && s.itemRowChecked]}
                      onPress={() => toggleItem(item.key, item.name)}
                      accessibilityRole="checkbox"
                      aria-checked={isChecked}
                      accessibilityState={{ checked: isChecked }}
                      accessibilityLabel={`${item.name}, ${qtyText}${isChecked ? ', marcado' : ''}`}
                    >
                      <View style={[s.checkbox, isChecked && s.checkboxChecked]}>
                        {isChecked ? <Text style={s.checkmark}>✓</Text> : null}
                      </View>
                      <View style={{ flex: 1 }}>
                        <Text style={[s.itemName, isChecked && s.itemNameChecked]}>
                          {item.name} • <Text style={s.itemQty}>{qtyText}</Text>
                        </Text>
                        {sourceText !== '' && (
                          <Text style={s.itemMeta}>Usado em: {sourceText}</Text>
                        )}
                        {item.has_allergen_conflict && item.allergen_warning && (
                          <Text style={s.itemWarning}>{item.allergen_warning}</Text>
                        )}
                      </View>
                    </TouchableOpacity>
                  );
                })}
              </View>
            ))
          )}
        </>
      )}
    </View>
  );
}

const s = StyleSheet.create({
  sectionTitle: { fontSize: 18, fontWeight: '700', color: t.navy, marginBottom: 4 },
  helperText: { fontSize: 14, color: t.muted, lineHeight: 20, marginBottom: 10 },
  periodLabel: { fontSize: 14, fontWeight: '600', color: t.navy, marginBottom: 6 },
  periodRow: { flexDirection: 'row', gap: 8, marginBottom: 8 },
  periodChip: {
    minHeight: 44,
    paddingHorizontal: 14,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: t.inputBorder,
    backgroundColor: t.white,
    justifyContent: 'center',
    alignItems: 'center',
  },
  periodChipActive: { backgroundColor: t.navy, borderColor: t.navy },
  periodChipText: { color: t.navy, fontWeight: '600', fontSize: 14 },
  periodChipTextActive: { color: t.white },
  noticeRow: { marginBottom: 10 },
  refreshButton: { minHeight: 44, justifyContent: 'center', alignSelf: 'flex-start' },
  refreshText: { color: t.navy, textDecorationLine: 'underline', fontWeight: '600', fontSize: 14 },
  errorText: { color: t.error, fontSize: 14, marginBottom: 4 },
  warningBox: {
    backgroundColor: '#FEF3F2',
    borderLeftWidth: 3,
    borderLeftColor: t.error,
    padding: 12,
    borderRadius: 8,
    marginBottom: 12,
  },
  warningTitle: { color: t.error, fontWeight: '700', fontSize: 14, marginBottom: 2 },
  warningText: { color: t.ink, fontSize: 13, lineHeight: 19 },
  summaryRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    flexWrap: 'wrap',
    gap: 8,
    marginBottom: 10,
  },
  summaryText: { fontSize: 14, fontWeight: '600', color: t.navy },
  clearButton: {
    minHeight: 44,
    paddingHorizontal: 12,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: t.inputBorder,
    justifyContent: 'center',
    alignItems: 'center',
  },
  clearButtonText: { color: t.navy, fontWeight: '600', fontSize: 13 },
  card: {
    backgroundColor: t.white,
    borderRadius: t.cardRadius,
    padding: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: t.divider,
  },
  cardTitle: { fontSize: 16, fontWeight: '700', color: t.navy, marginBottom: 10 },
  itemRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 12,
    minHeight: 44,
    paddingVertical: 8,
    borderTopWidth: 1,
    borderTopColor: t.divider,
  },
  itemRowChecked: { opacity: 0.7 },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: 6,
    borderWidth: 2,
    borderColor: t.navy,
    justifyContent: 'center',
    alignItems: 'center',
    marginTop: 2,
  },
  checkboxChecked: { backgroundColor: t.navy },
  checkmark: { color: t.white, fontWeight: '700', fontSize: 13 },
  itemName: { fontSize: 15, color: t.ink, lineHeight: 21 },
  itemNameChecked: { textDecorationLine: 'line-through', color: t.muted },
  itemQty: { fontWeight: '700', color: t.navy },
  itemMeta: { fontSize: 12, color: t.muted, marginTop: 2 },
  itemWarning: { fontSize: 12, color: t.error, marginTop: 2, fontWeight: '600' },
});
