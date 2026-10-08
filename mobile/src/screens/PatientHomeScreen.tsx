import React, { useState } from 'react';
import { StyleSheet, Text, View, ScrollView, TouchableOpacity, SafeAreaView, AccessibilityInfo } from 'react-native';

export type NavigationTab = 'Hoje' | 'Plano' | 'Diario' | 'Evolucao' | 'Perfil';

export default function PatientHomeScreen() {
  const [currentTab, setCurrentTab] = useState<NavigationTab>('Hoje');
  const [waterMl, setWaterMl] = useState(1250);
  const [syncedOffline, setSyncedOffline] = useState(true);

  const addWater = (ml: number) => {
    setWaterMl(prev => prev + ml);
  };

  return (
    <SafeAreaView style={styles.container}>
      {/* Offline Sync Banner */}
      <View 
        style={styles.syncBanner} 
        accessible={true} 
        accessibilityRole="summary" 
        accessibilityLabel="Status de sincronização: dados atualizados localmente"
      >
        <Text style={styles.syncText}>
          {syncedOffline ? '✓ Dados sincronizados com seu consultório' : '⚠️ Modo offline ativo — alterações salvas localmente'}
        </Text>
      </View>

      <ScrollView contentContainerStyle={styles.scroll}>
        {currentTab === 'Hoje' && (
          <>
            {/* Header com Design System Premium */}
            <View style={styles.header}>
              <Text 
                style={styles.greeting} 
                accessibilityRole="header"
              >
                Olá, Juliana 👋
              </Text>
              <Text style={styles.subGreeting}>Acompanhamento nutricional dedicado</Text>
            </View>

            {/* Card Próxima Consulta (NI2-137) */}
            <View 
              style={styles.card} 
              accessible={true}
              accessibilityLabel="Próxima consulta com Dra. Paula em 15 de Outubro às 14:00"
            >
              <Text style={styles.cardTitle}>🗓️ Próximo Atendimento</Text>
              <Text style={styles.appointmentDate}>15 de Outubro de 2026 às 14:00</Text>
              <Text style={styles.appointmentDoctor}>Dra. Paula Nutricionista • Teleconsulta</Text>
              <TouchableOpacity 
                style={styles.copperButton}
                accessibilityRole="button"
                accessibilityLabel="Confirmar presença na próxima consulta"
              >
                <Text style={styles.copperButtonText}>Confirmar Presença</Text>
              </TouchableOpacity>
            </View>

            {/* Card de Hidratação Diária */}
            <View 
              style={styles.card}
              accessible={true}
              accessibilityLabel={`Meta de hidratação: ${waterMl} ml de 2500 ml`}
            >
              <Text style={styles.cardTitle}>💧 Hidratação Diária</Text>
              <Text style={styles.waterValue}>{waterMl} ml / 2.500 ml</Text>
              <View style={styles.buttonRow}>
                <TouchableOpacity 
                  style={styles.navyButton} 
                  onPress={() => addWater(250)}
                  accessibilityRole="button"
                  accessibilityLabel="Adicionar 250 mililitros de água"
                >
                  <Text style={styles.navyButtonText}>+ 250 ml</Text>
                </TouchableOpacity>
                <TouchableOpacity 
                  style={styles.navyButton} 
                  onPress={() => addWater(500)}
                  accessibilityRole="button"
                  accessibilityLabel="Adicionar 500 mililitros de água"
                >
                  <Text style={styles.navyButtonText}>+ 500 ml</Text>
                </TouchableOpacity>
              </View>
            </View>

            {/* Refeições de Hoje (NI2-132 e NI2-134) */}
            <Text style={styles.sectionTitle} accessibilityRole="header">Prescrição do Dia</Text>

            <View style={styles.mealCard}>
              <View style={styles.mealHeader}>
                <Text style={styles.mealName}>Almoço</Text>
                <Text style={styles.mealTime}>12:30</Text>
              </View>
              <Text style={styles.mealItem}>• 150g de Arroz integral cozido</Text>
              <Text style={styles.mealItem}>• 150g de Peito de frango grelhado</Text>
              <Text style={styles.mealItem}>• Salada crua variada com azeite extravirgem</Text>
              
              <View style={styles.actionRow}>
                <TouchableOpacity 
                  style={styles.subButton}
                  accessibilityRole="button"
                  accessibilityLabel="Ver opções de substituição autorizadas para esta refeição"
                >
                  <Text style={styles.subButtonText}>Ver substituições autorizadas</Text>
                </TouchableOpacity>
                <TouchableOpacity 
                  style={styles.registerMealButton}
                  accessibilityRole="button"
                  accessibilityLabel="Registrar consumo desta refeição no diário alimentar"
                >
                  <Text style={styles.registerMealText}>Registrar Consumo</Text>
                </TouchableOpacity>
              </View>
            </View>
          </>
        )}

        {currentTab === 'Plano' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Plano Alimentar Completo</Text>
            <Text style={styles.helperText}>Versão publicada vigente: V1 (Prescrito pela Dra. Paula)</Text>
            <View style={styles.card}>
              <Text style={styles.cardTitle}>Orientações Gerais</Text>
              <Text style={styles.mealItem}>• Mastigar devagar e beber líquidos fora das refeições principais.</Text>
              <Text style={styles.mealItem}>• Evitar substituições não aprovadas pelo nutricionista.</Text>
            </View>
          </View>
        )}

        {currentTab === 'Diario' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Diário Alimentar</Text>
            <Text style={styles.helperText}>Histórico com distinção clara entre planejado e realizado</Text>
            <View style={styles.card}>
              <Text style={styles.cardTitle}>Refeição Registrada Hoje • 12:45</Text>
              <Text style={styles.mealItem}>Consumiu: Arroz integral + Peito de frango + Salada</Text>
              <Text style={styles.diaryStatus}>✓ Registrado pelo paciente (Autorrelato rastreável)</Text>
            </View>
          </View>
        )}

        {currentTab === 'Evolucao' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Evolução & Metas</Text>
            <View style={styles.card}>
              <Text style={styles.cardTitle}>📊 Medição Clínica Oficial</Text>
              <Text style={styles.mealItem}>Peso: 62.0 kg | IMC: 21.97</Text>
              <Text style={styles.metaLabel}>Aferido presencialmente pelo profissional</Text>
            </View>
            <View style={styles.card}>
              <Text style={styles.cardTitle}>📝 Autorrelato Semanal</Text>
              <Text style={styles.mealItem}>Peso informado: 61.8 kg</Text>
              <Text style={styles.metaLabel}>Origem: Autorrelato do paciente (balança residencial)</Text>
            </View>
          </View>
        )}

        {currentTab === 'Perfil' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Perfil & Privacidade</Text>
            <View style={styles.card}>
              <Text style={styles.cardTitle}>Juliana Mendes</Text>
              <Text style={styles.mealItem}>Vínculo: Clínica Clyo Nutrição Integrada</Text>
              <Text style={styles.mealItem}>Consentimento LGPD: V1 Ativo e Auditado</Text>
            </View>
          </View>
        )}
      </ScrollView>

      {/* Barra de Navegação Inferior Premium (NI2-140) */}
      <View style={styles.navBar} accessibilityRole="tablist">
        {(['Hoje', 'Plano', 'Diario', 'Evolucao', 'Perfil'] as NavigationTab[]).map(tab => (
          <TouchableOpacity
            key={tab}
            style={[styles.navItem, currentTab === tab && styles.navItemActive]}
            onPress={() => setCurrentTab(tab)}
            accessibilityRole="tab"
            accessibilityState={{ selected: currentTab === tab }}
            accessibilityLabel={`Aba ${tab}`}
          >
            <Text style={[styles.navText, currentTab === tab && styles.navTextActive]}>
              {tab}
            </Text>
          </TouchableOpacity>
        ))}
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#FAF7F2', // Fundo branco quente Premium
  },
  syncBanner: {
    backgroundColor: '#EBF3FA',
    paddingVertical: 8,
    paddingHorizontal: 16,
    alignItems: 'center',
    borderBottomWidth: 1,
    borderBottomColor: '#D0E1F2',
  },
  syncText: {
    fontSize: 12,
    fontWeight: '600',
    color: '#0F2747',
  },
  scroll: {
    padding: 20,
    paddingBottom: 100,
  },
  header: {
    marginBottom: 20,
  },
  greeting: {
    fontSize: 26,
    fontWeight: '700',
    color: '#0F2747', // Navy Premium
  },
  subGreeting: {
    fontSize: 14,
    color: '#526071',
    marginTop: 4,
  },
  card: {
    backgroundColor: '#FFFFFF',
    borderRadius: 14,
    padding: 16,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: '#E5E7EB',
  },
  cardTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: '#0F2747',
    marginBottom: 8,
  },
  appointmentDate: {
    fontSize: 15,
    fontWeight: '600',
    color: '#0F2747',
  },
  appointmentDoctor: {
    fontSize: 13,
    color: '#526071',
    marginBottom: 12,
  },
  waterValue: {
    fontSize: 22,
    fontWeight: '700',
    color: '#0F2747',
    marginBottom: 12,
  },
  buttonRow: {
    flexDirection: 'row',
    gap: 10,
  },
  navyButton: {
    backgroundColor: '#0F2747',
    paddingVertical: 10,
    paddingHorizontal: 16,
    borderRadius: 8,
    minHeight: 44, // Alvo de toque WCAG
    justifyContent: 'center',
  },
  navyButtonText: {
    color: '#FFFFFF',
    fontWeight: '600',
    fontSize: 14,
  },
  copperButton: {
    backgroundColor: '#E67A3F', // Copper Premium
    paddingVertical: 10,
    paddingHorizontal: 16,
    borderRadius: 8,
    alignItems: 'center',
    minHeight: 44,
    justifyContent: 'center',
  },
  copperButtonText: {
    color: '#FFFFFF',
    fontWeight: '700',
    fontSize: 14,
  },
  sectionTitle: {
    fontSize: 18,
    fontWeight: '700',
    color: '#0F2747',
    marginBottom: 12,
    marginTop: 8,
  },
  mealCard: {
    backgroundColor: '#FFFFFF',
    borderRadius: 14,
    padding: 16,
    marginBottom: 14,
    borderWidth: 1,
    borderColor: '#E5E7EB',
  },
  mealHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 10,
  },
  mealName: {
    fontSize: 17,
    fontWeight: '700',
    color: '#0F2747',
  },
  mealTime: {
    fontSize: 14,
    fontWeight: '600',
    color: '#E67A3F',
  },
  mealItem: {
    fontSize: 14,
    color: '#1F2937',
    marginBottom: 6,
    lineHeight: 20,
  },
  actionRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginTop: 12,
    paddingTop: 10,
    borderTopWidth: 1,
    borderTopColor: '#F3F4F6',
  },
  subButton: {
    paddingVertical: 8,
    minHeight: 44,
    justifyContent: 'center',
  },
  subButtonText: {
    fontSize: 13,
    color: '#E67A3F',
    fontWeight: '600',
  },
  registerMealButton: {
    backgroundColor: '#0F2747',
    paddingVertical: 8,
    paddingHorizontal: 12,
    borderRadius: 6,
    minHeight: 44,
    justifyContent: 'center',
  },
  registerMealText: {
    color: '#FFFFFF',
    fontSize: 13,
    fontWeight: '600',
  },
  tabContent: {
    paddingVertical: 10,
  },
  helperText: {
    fontSize: 13,
    color: '#526071',
    marginBottom: 12,
  },
  diaryStatus: {
    fontSize: 12,
    fontWeight: '600',
    color: '#027A48',
    marginTop: 6,
  },
  metaLabel: {
    fontSize: 12,
    color: '#667085',
    marginTop: 4,
    fontStyle: 'italic',
  },
  navBar: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    height: 70,
    backgroundColor: '#FFFFFF',
    borderTopWidth: 1,
    borderTopColor: '#E5E7EB',
    flexDirection: 'row',
    justifyContent: 'space-around',
    alignItems: 'center',
    paddingBottom: 10,
  },
  navItem: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 8,
    paddingHorizontal: 12,
    minWidth: 50,
    minHeight: 44,
  },
  navItemActive: {
    borderBottomWidth: 2,
    borderBottomColor: '#E67A3F',
  },
  navText: {
    fontSize: 12,
    fontWeight: '500',
    color: '#667085',
  },
  navTextActive: {
    color: '#0F2747',
    fontWeight: '700',
  },
});
