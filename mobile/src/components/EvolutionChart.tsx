import React, { useState } from 'react';
import { View, Text, StyleSheet, LayoutChangeEvent } from 'react-native';
import { patientTokens as t } from '../theme/patient';

export interface ChartPoint { date: string; value: number; source: 'clinical_official' | 'patient_self_report' | string; }
export interface ChartSeries { type: string; label: string; unit: string; points: ChartPoint[]; }

const HEIGHT = 150;
const PAD = 10;
const toTime = (d: string) => new Date(d.replace(' ', 'T')).getTime();
const fmtNum = (v: number) => v.toLocaleString('pt-BR', { maximumFractionDigits: 1 });
const fmtDate = (d: string) => { const x = new Date(d.replace(' ', 'T')); return isNaN(x.getTime()) ? d : x.toLocaleDateString('pt-BR'); };

/** Resumo em texto do gráfico — é o que o leitor de tela anuncia (NI2-135). */
export function describeSeries(s: ChartSeries, goal?: number | null): string {
  const p = s.points;
  if (!p.length) return `${s.label}: sem registros no período.`;
  const values = p.map((x) => x.value);
  const clin = p.filter((x) => x.source === 'clinical_official').length;
  return `${s.label}: ${p.length} registro(s) de ${fmtDate(p[0].date)} a ${fmtDate(p[p.length - 1].date)}` +
    ` (${clin} do nutricionista, ${p.length - clin} seus). Primeiro ${fmtNum(p[0].value)} ${s.unit}, último ${fmtNum(p[p.length - 1].value)} ${s.unit},` +
    ` menor ${fmtNum(Math.min(...values))}, maior ${fmtNum(Math.max(...values))}.` + (goal != null ? ` Meta: ${fmtNum(goal)} ${s.unit}.` : '');
}

/**
 * Gráfico de evolução no tempo, desenhado com Views (sem dependência nativa nova).
 * Fonte diferenciada por forma e cor: círculo cheio = nutricionista; quadrado vazado = você.
 */
export default function EvolutionChart({ series, goal }: { series: ChartSeries; goal?: number | null }) {
  const [width, setWidth] = useState(0);
  const onLayout = (e: LayoutChangeEvent) => setWidth(e.nativeEvent.layout.width);
  const pts = series.points.filter((p) => Number.isFinite(p.value) && !isNaN(toTime(p.date)));
  const values = pts.map((p) => p.value).concat(goal != null ? [goal] : []);
  let min = values.length ? Math.min(...values) : 0, max = values.length ? Math.max(...values) : 1;
  if (max - min < 1) { min -= 1; max += 1; }
  const span = max - min; min -= span * 0.08; max += span * 0.08;
  const t0 = pts.length ? toTime(pts[0].date) : 0, t1 = pts.length ? toTime(pts[pts.length - 1].date) : 1;
  const plotW = Math.max(0, width - 2 * PAD), plotH = HEIGHT - 2 * PAD;
  const x = (p: ChartPoint) => PAD + (t1 === t0 ? plotW / 2 : ((toTime(p.date) - t0) / (t1 - t0)) * plotW);
  const y = (v: number) => PAD + (1 - (v - min) / (max - min)) * plotH;
  const summary = describeSeries(series, goal);

  return (
    <View>
      <Text style={styles.title}>{series.label} ({series.unit})</Text>
      <View accessible accessibilityRole="image" accessibilityLabel={summary} onLayout={onLayout} style={styles.plot}>
        {width > 0 && (
          <>
            <Text style={[styles.axis, { top: 0 }]} importantForAccessibility="no-hide-descendants">{fmtNum(max)}</Text>
            <Text style={[styles.axis, { bottom: 0 }]} importantForAccessibility="no-hide-descendants">{fmtNum(min)}</Text>
            {goal != null && <View style={[styles.goal, { top: y(goal) }]} />}
            {pts.slice(1).map((p, i) => {
              const a = pts[i]; const x1 = x(a), y1 = y(a.value), x2 = x(p), y2 = y(p.value);
              const len = Math.hypot(x2 - x1, y2 - y1); const ang = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
              return <View key={'l' + i} style={[styles.line, { left: (x1 + x2) / 2 - len / 2, top: (y1 + y2) / 2, width: len, transform: [{ rotate: `${ang}deg` }] }]} />;
            })}
            {pts.map((p, i) => p.source === 'clinical_official'
              ? <View key={'p' + i} style={[styles.clinical, { left: x(p) - 5, top: y(p.value) - 5 }]} />
              : <View key={'p' + i} style={[styles.self, { left: x(p) - 5, top: y(p.value) - 5 }]} />)}
          </>
        )}
      </View>
      <View style={styles.legend} importantForAccessibility="no-hide-descendants">
        <View style={styles.legendItem}><View style={[styles.clinical, styles.legendMark]} /><Text style={styles.legendText}>Nutricionista</Text></View>
        <View style={styles.legendItem}><View style={[styles.self, styles.legendMark]} /><Text style={styles.legendText}>Você</Text></View>
        {goal != null && <View style={styles.legendItem}><View style={[styles.goalMark]} /><Text style={styles.legendText}>Meta</Text></View>}
      </View>
      <Text style={styles.caption}>{pts.length ? `${fmtDate(pts[0].date)} — ${fmtDate(pts[pts.length - 1].date)}` : 'Sem registros no período'}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  title: { fontWeight: '700', color: t.navy, fontSize: 15, marginBottom: 6 },
  plot: { backgroundColor: t.chartWash, borderRadius: 14, height: HEIGHT, borderLeftWidth: 1, borderBottomWidth: 1, borderColor: t.border, position: 'relative', overflow: 'hidden' },
  axis: { position: 'absolute', left: 4, fontSize: 11, color: t.muted },
  line: { position: 'absolute', height: 2, backgroundColor: t.success },
  clinical: { position: 'absolute', width: 10, height: 10, borderRadius: 5, backgroundColor: t.navy },
  self: { position: 'absolute', width: 10, height: 10, borderWidth: 2, borderColor: t.copper, backgroundColor: t.white },
  goal: { position: 'absolute', left: 0, right: 0, borderTopWidth: 1, borderStyle: 'dashed', borderColor: t.success },
  legend: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, marginTop: 6 },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  legendMark: { position: 'relative', left: 0, top: 0 },
  goalMark: { width: 16, borderTopWidth: 1, borderStyle: 'dashed', borderColor: t.success },
  legendText: { fontSize: 12, color: t.ink },
  caption: { fontSize: 12, color: t.muted, marginTop: 4 },
});
