import React from 'react';
import { StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { patientTokens as t, patientType } from '../theme/patient';

type Props = { name: string; meals: number; hasPlan: boolean; waterMl: number; waterGoal: number | null; onPlan: () => void };
/** Uses actual prescribed meals and confirmed hydration; never infers calorie targets. */
export default function PatientOverview({ name, meals, hasPlan, waterMl, waterGoal, onPlan }: Props) {
  const percent = waterGoal && waterGoal > 0 ? Math.min(100, Math.round(waterMl / waterGoal * 100)) : null;
  return <View style={s.hero} testID="patient-overview">
    <Text style={s.eyebrow}>{new Date().toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' })}</Text>
    <Text accessibilityRole="header" style={s.greeting}>Olá, {name}</Text>
    <Text style={s.subtitle}>Um dia de cada vez. No seu ritmo.</Text>
    <View style={s.overview}>
      <View style={s.copy}>
        <Text style={s.planLabel}>SEU PLANO DE HOJE</Text>
        <Text style={s.planTitle}>{hasPlan ? meals + (meals === 1 ? ' refeição no seu plano' : ' refeições no seu plano') : 'Seu cuidado começa aqui'}</Text>
        <Text style={s.description}>{hasPlan ? 'Orientações do seu nutricionista, sempre por perto.' : 'Quando seu plano for publicado, ele aparecerá aqui.'}</Text>
      </View>
      <View style={s.waterSummary} accessible accessibilityRole="image" accessibilityLabel={percent !== null ? 'Água confirmada hoje: ' + waterMl + ' ml. ' + percent + '% da meta definida pelo nutricionista.' : 'Água confirmada hoje: ' + waterMl + ' ml. Sem meta definida.'}>
        <View style={s.ring}>
          <Text style={s.ringValue}>{percent !== null ? percent + '%' : waterMl.toLocaleString('pt-BR')}</Text>
          <Text style={s.ringCaption}>{percent !== null ? 'da meta' : 'ml'}</Text>
        </View>
        <Text style={s.waterLabel}>Água hoje</Text>
      </View>
    </View>
    <TouchableOpacity accessibilityRole="button" accessibilityLabel={hasPlan ? 'Ver meu plano alimentar' : 'Consultar meu plano alimentar'} onPress={onPlan} style={s.action}>
      <Text style={s.actionText}>{hasPlan ? 'Ver meu plano' : 'Consultar meu plano'}</Text><Text style={s.arrow} accessible={false}>→</Text>
    </TouchableOpacity>
  </View>;
}
const s = StyleSheet.create({
  hero: { backgroundColor: t.forest, borderRadius: 28, padding: 24, marginBottom: 28 },
  eyebrow: { ...patientType.caption, color: t.onForestMuted, marginBottom: 16 },
  greeting: { ...patientType.title, color: t.onForest },
  subtitle: { ...patientType.body, color: t.onForestMuted, marginTop: 6 },
  overview: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 20, marginVertical: 24 },
  copy: { flexGrow: 1, flexShrink: 1, flexBasis: 140 },
  planLabel: { fontSize: 10, lineHeight: 16, letterSpacing: 1.1, fontWeight: '700', color: t.mint, marginBottom: 8 },
  planTitle: { fontSize: 21, lineHeight: 28, fontWeight: '600', color: t.onForest },
  description: { ...patientType.caption, color: t.onForestMuted, marginTop: 8 },
  waterSummary: { alignItems: 'center' },
  ring: { minWidth: 88, minHeight: 88, padding: 12, borderRadius: 999, borderWidth: 3, borderColor: t.mint, alignItems: 'center', justifyContent: 'center' },
  ringValue: { color: t.onForest, fontSize: 22, fontWeight: '700' },
  ringCaption: { color: t.onForestMuted, fontSize: 11 },
  waterLabel: { color: t.onForestMuted, fontSize: 11, marginTop: 8 },
  action: { backgroundColor: t.mint, borderRadius: t.buttonRadius, minHeight: t.touch, paddingVertical: 14, paddingHorizontal: 20, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 },
  actionText: { fontSize: 15, fontWeight: '700', color: t.forest, flexShrink: 1 },
  arrow: { color: t.forest, fontSize: 22 },
});
