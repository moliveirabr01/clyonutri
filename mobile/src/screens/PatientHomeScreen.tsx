import React, { useState, useEffect, useCallback } from 'react';
import {
  StyleSheet,
  Text,
  View,
  ScrollView,
  TouchableOpacity,
  SafeAreaView,
  ActivityIndicator,
  RefreshControl,
  Alert,
} from 'react-native';
import {
  ApiService,
  PatientAuthData,
  PatientHomeData,
  FoodDiaryEntry,
  EvolutionData,
} from '../services/api';

export type NavigationTab = 'Hoje' | 'Plano' | 'Diario' | 'Evolucao' | 'Perfil';

export default function PatientHomeScreen({ session, onLogout }: { session: PatientAuthData; onLogout: () => void }) {
  const [currentTab, setCurrentTab] = useState<NavigationTab>('Hoje');
  const [loading, setLoading] = useState<boolean>(true);
  const [refreshing, setRefreshing] = useState<boolean>(false);
  const [isOffline, setIsOffline] = useState<boolean>(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Dados reais carregados da API
  const [homeData, setHomeData] = useState<PatientHomeData | null>(null);
  const [diaryEntries, setDiaryEntries] = useState<FoodDiaryEntry[]>([]);
  const [evolutionData, setEvolutionData] = useState<EvolutionData | null>(null);
  const [diaryError, setDiaryError] = useState<string | null>(null);
  const [evolutionError, setEvolutionError] = useState<string | null>(null);
  const [logoutError, setLogoutError] = useState<string | null>(null);
  const [loggingOut, setLoggingOut] = useState(false);
  const [actionLoading, setActionLoading] = useState<boolean>(false);

  // Carregamento principal
  const fetchAllData = useCallback(async () => {
    try {
      setErrorMessage(null);
      const data = await ApiService.getHome();
      setHomeData(data);
      setIsOffline(false);

      // Carregar diário e evolução de forma não bloqueante
      try {
        const diary = await ApiService.getFoodDiary();
        setDiaryEntries(diary.entries || []);
        setDiaryError(null);
      } catch (_) { setDiaryError('Não foi possível carregar o diário. Tente atualizar.'); }

      try {
        const evo = await ApiService.getEvolution();
        setEvolutionData(evo);
        setEvolutionError(null);
      } catch (_) { setEvolutionError('Não foi possível carregar a evolução. Tente atualizar.'); }
    } catch (err: any) {
      setIsOffline(true);
      setErrorMessage(err.message || 'Falha na comunicação com o servidor');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    fetchAllData();
  }, [fetchAllData]);

  const onRefresh = useCallback(() => {
    setRefreshing(true);
    fetchAllData();
  }, [fetchAllData]);

  // Registro real e persistido de hidratação (NI2-176)
  const handleAddWater = async (amountMl: number) => {
    if (!homeData) return;
    setActionLoading(true);
    try {
      const res = await ApiService.logHydration(amountMl);
      setHomeData(prev => prev ? {
        ...prev,
        water_ml_today: res.total_water_today ?? (prev.water_ml_today + amountMl),
      } : null);
      setIsOffline(false);
    } catch (err: any) {
      Alert.alert('Erro ao registrar', 'Não foi possível salvar o registro de água no servidor.');
      setIsOffline(true);
    } finally {
      setActionLoading(false);
    }
  };

  // Confirmação de consulta real (NI2-176)
  const handleConfirmAppointment = async () => {
    if (!homeData?.next_appointment) return;
    try {
      await ApiService.confirmAppointment(homeData.next_appointment.id);
      Alert.alert('Presença Confirmada', 'Sua presença na consulta foi confirmada com sucesso.');
      setHomeData(prev => prev && prev.next_appointment ? {
        ...prev,
        next_appointment: { ...prev.next_appointment, status: 'confirmed' },
      } : prev);
    } catch (err: any) {
      Alert.alert('Erro', 'Não foi possível confirmar o agendamento no servidor.');
    }
  };

  // Registro de consumo de refeição no diário alimentar (NI2-176)
  const handleRegisterMeal = async (mealName: string) => {
    try {
      const entry = await ApiService.logMeal({
        meal_name: mealName,
        description: `Consumo registrado via aplicativo móvel às ${new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`,
      });
      setDiaryEntries(prev => [entry, ...prev]);
      Alert.alert('Refeição Registrada', `${mealName} foi anotado no seu Diário Alimentar.`);
    } catch (err: any) {
      Alert.alert('Erro', 'Não foi possível salvar o consumo no diário alimentar.');
    }
  };

  // Tela de Carregamento inicial
  if (loading) {
    return (
      <SafeAreaView style={styles.centerContainer}>
        <ActivityIndicator size="large" color="#0F2747" accessibilityLabel="Carregando dados do paciente" />
        <Text style={styles.loadingText}>Conectando ao seu consultório nutricional...</Text>
      </SafeAreaView>
    );
  }

  if (!homeData) return <SafeAreaView style={styles.centerContainer}>
    <Text accessibilityRole="alert" style={styles.errorAlertText}>{errorMessage || 'Não foi possível carregar seus dados.'}</Text>
    <TouchableOpacity onPress={fetchAllData} accessibilityRole="button" accessibilityLabel="Tentar novamente"><Text>Tentar novamente</Text></TouchableOpacity>
    <TouchableOpacity onPress={async () => { try { await ApiService.logout(); onLogout(); } catch (_) { setErrorMessage('Falha ao encerrar sessão. Tente novamente.'); } }} accessibilityRole="button"><Text>Encerrar sessão</Text></TouchableOpacity>
  </SafeAreaView>;

  const patientName = homeData.patient_name;
  const waterMl = homeData?.water_ml_today ?? 0;
  const waterGoal = homeData?.water_goal_ml ?? null;
  const nextApp = homeData?.next_appointment;
  const activePlan = homeData?.active_plan;

  return (
    <SafeAreaView style={styles.container}>
      {/* Banner de Sincronização e Conectividade Real (Critério 4) */}
      <View
        style={[styles.syncBanner, isOffline && styles.syncBannerOffline]}
        accessible={true}
        accessibilityRole="summary"
        accessibilityLabel={
          isOffline
            ? 'Atenção: Modo offline ou falha de conexão com o servidor'
            : 'Resumo atualizado com seu consultório'
        }
      >
        <Text style={[styles.syncText, isOffline && styles.syncTextOffline]}>
          {isOffline
            ? '⚠️ Conexão indisponível — dados podem estar desatualizados'
            : '✓ Resumo atualizado com seu consultório'}
        </Text>
      </View>

      {errorMessage && (
        <View style={styles.errorAlert}>
          <Text style={styles.errorAlertText}>{errorMessage}</Text>
          <TouchableOpacity
            style={styles.retryButton}
            onPress={fetchAllData}
            accessibilityRole="button"
            accessibilityLabel="Tentar reconectar ao servidor"
          >
            <Text style={styles.retryButtonText}>Tentar Novamente</Text>
          </TouchableOpacity>
        </View>
      )}

      <ScrollView
        contentContainerStyle={styles.scroll}
        refreshControl={
          <RefreshControl refreshing={refreshing} onRefresh={onRefresh} colors={['#0F2747']} tintColor="#0F2747" />
        }
      >
        {currentTab === 'Hoje' && (
          <>
            {/* Header com Design System Premium */}
            <View style={styles.header}>
              <Text
                style={styles.greeting}
                accessibilityRole="header"
              >
                Olá, {patientName} 👋
              </Text>
              <Text style={styles.subGreeting}>Acompanhamento nutricional dedicado</Text>
            </View>

            {/* Card Próxima Consulta com dados reais e empty state (Critério 2) */}
            <View
              style={styles.card}
              accessible={true}
              accessibilityLabel={
                nextApp
                  ? `Próxima consulta com ${nextApp.professional_name || 'Nutricionista'} em ${nextApp.scheduled_at}`
                  : 'Nenhuma consulta futura agendada'
              }
            >
              <Text style={styles.cardTitle}>🗓️ Próximo Atendimento</Text>
              {nextApp ? (
                <>
                  <Text style={styles.appointmentDate}>{nextApp.scheduled_at}</Text>
                  <Text style={styles.appointmentDoctor}>
                    {nextApp.professional_name || 'Nutricionista'} • Status: {nextApp.status === 'confirmed' ? 'Confirmado' : 'Agendado'}
                  </Text>
                  {nextApp.meeting_url && (
                    <Text style={styles.telehealthLink}>Teleconsulta: Link disponível</Text>
                  )}
                  {nextApp.status !== 'confirmed' && (
                    <TouchableOpacity
                      style={styles.copperButton}
                      onPress={handleConfirmAppointment}
                      accessibilityRole="button"
                      accessibilityLabel="Confirmar presença na próxima consulta"
                    >
                      <Text style={styles.copperButtonText}>Confirmar Presença</Text>
                    </TouchableOpacity>
                  )}
                </>
              ) : (
                <View style={styles.emptyCard}>
                  <Text style={styles.emptyCardText}>Nenhum atendimento agendado no momento.</Text>
                </View>
              )}
            </View>

            {/* Card de Hidratação Diária com persistência no servidor (Critério 3 e PX-20 / NI2-313) */}
            <View
              style={styles.card}
              accessible={true}
              accessibilityLabel={
                waterGoal !== null && waterGoal > 0
                  ? `Meta de hidratação: ${waterMl} ml de ${waterGoal} ml`
                  : `Hidratação registrada: ${waterMl} ml. Nenhuma meta hídrica prescrita.`
              }
            >
              <Text style={styles.cardTitle}>💧 Hidratação Diária</Text>
              {waterGoal !== null && waterGoal > 0 ? (
                <>
                  <Text style={styles.waterValue}>{waterMl} ml / {waterGoal.toLocaleString('pt-BR')} ml</Text>
                  <View style={styles.progressBarBackground}>
                    <View
                      style={[
                        styles.progressBarFill,
                        { width: `${Math.min(100, Math.round((waterMl / waterGoal) * 100))}%` },
                      ]}
                    />
                  </View>
                </>
              ) : (
                <View style={{ marginBottom: 12 }}>
                  <Text style={styles.waterValue}>{waterMl} ml</Text>
                  <Text style={styles.metaLabel}>Meta hídrica não definida pelo nutricionista</Text>
                </View>
              )}

              <View style={styles.buttonRow}>
                <TouchableOpacity
                  style={styles.navyButton}
                  onPress={() => handleAddWater(250)}
                  disabled={actionLoading}
                  accessibilityRole="button"
                  accessibilityLabel="Adicionar 250 mililitros de água"
                >
                  <Text style={styles.navyButtonText}>+ 250 ml</Text>
                </TouchableOpacity>
                <TouchableOpacity
                  style={styles.navyButton}
                  onPress={() => handleAddWater(500)}
                  disabled={actionLoading}
                  accessibilityRole="button"
                  accessibilityLabel="Adicionar 500 mililitros de água"
                >
                  <Text style={styles.navyButtonText}>+ 500 ml</Text>
                </TouchableOpacity>
              </View>
            </View>

            {/* Refeições de Hoje com dados reais do plano ou Empty State (Critério 2) */}
            <Text style={styles.sectionTitle} accessibilityRole="header">Prescrição do Dia</Text>

            {activePlan && activePlan.meals && activePlan.meals.length > 0 ? (
              activePlan.meals.map((meal) => (
                <View key={meal.id} style={styles.mealCard}>
                  <View style={styles.mealHeader}>
                    <Text style={styles.mealName}>{meal.name}</Text>
                    <Text style={styles.mealTime}>{meal.time || '--:--'}</Text>
                  </View>

                  {meal.items && meal.items.length > 0 ? (
                    meal.items.map((item) => (
                      <View key={item.id} style={styles.mealItemRow}>
                        <Text style={styles.mealItem}>
                          • {item.quantity} {item.unit} de {item.food_name}
                        </Text>
                        {item.substitutions && item.substitutions.length > 0 && (
                          <Text style={styles.substitutionText}>
                            (Substituições: {item.substitutions.map(s => s.food_name).join(', ')})
                          </Text>
                        )}
                      </View>
                    ))
                  ) : (
                    <Text style={styles.emptyItemsText}>Nenhum item cadastrado nesta refeição.</Text>
                  )}

                  <View style={styles.actionRow}>
                    <TouchableOpacity
                      style={styles.registerMealButton}
                      onPress={() => handleRegisterMeal(meal.name)}
                      accessibilityRole="button"
                      accessibilityLabel={`Registrar consumo da refeição ${meal.name}`}
                    >
                      <Text style={styles.registerMealText}>Registrar Consumo</Text>
                    </TouchableOpacity>
                  </View>
                </View>
              ))
            ) : (
              <View style={styles.emptyContainer}>
                <Text style={styles.emptyTitle}>📋 Nenhum Plano Publicado</Text>
                <Text style={styles.emptyDescription}>
                  Seu nutricionista ainda não publicou um plano alimentar ativo. Assim que a prescrição for disponibilizada, você verá suas refeições aqui.
                </Text>
              </View>
            )}
          </>
        )}

        {currentTab === 'Plano' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Plano Alimentar Completo</Text>
            {activePlan ? (
              <>
                <Text style={styles.helperText}>
                  {activePlan.title || 'Plano Vigente'} (Status: {activePlan.status})
                </Text>
                {activePlan.notes && (
                  <View style={styles.card}>
                    <Text style={styles.cardTitle}>Orientações Gerais</Text>
                    <Text style={styles.mealItem}>{activePlan.notes}</Text>
                  </View>
                )}
                {activePlan.meals?.map((m) => (
                  <View key={m.id} style={styles.card}>
                    <Text style={styles.cardTitle}>{m.name} ({m.time})</Text>
                    {m.items?.map((it) => (
                      <Text key={it.id} style={styles.mealItem}>
                        • {it.food_name} ({it.quantity} {it.unit})
                      </Text>
                    ))}
                  </View>
                ))}
              </>
            ) : (
              <View style={styles.emptyContainer}>
                <Text style={styles.emptyTitle}>Nenhum plano ativo</Text>
                <Text style={styles.emptyDescription}>
                  Aguardando elaboração do plano pelo seu profissional.
                </Text>
              </View>
            )}
          </View>
        )}

        {currentTab === 'Diario' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Diário Alimentar</Text>
            <Text style={styles.helperText}>Histórico com distinção clara entre planejado e realizado</Text>

            {diaryError ? <Text accessibilityRole="alert" style={styles.errorAlertText}>{diaryError}</Text> : diaryEntries.length > 0 ? (
              diaryEntries.map((entry) => (
                <View key={entry.id} style={styles.card}>
                  <Text style={styles.cardTitle}>
                    {entry.meal_name} • {entry.consumed_at}
                  </Text>
                  {entry.description && (
                    <Text style={styles.mealItem}>{entry.description}</Text>
                  )}
                  {entry.feedback_from_professional && (
                    <View style={styles.feedbackBox}>
                      <Text style={styles.feedbackTitle}>Feedback do Nutricionista:</Text>
                      <Text style={styles.feedbackText}>{entry.feedback_from_professional}</Text>
                    </View>
                  )}
                  <Text style={styles.diaryStatus}>✓ Registrado pelo paciente</Text>
                </View>
              ))
            ) : (
              <View style={styles.emptyContainer}>
                <Text style={styles.emptyTitle}>Nenhum registro ainda hoje</Text>
                <Text style={styles.emptyDescription}>
                  Clique em &ldquo;Registrar Consumo&rdquo; na aba Hoje para anotar suas refeições realizadas.
                </Text>
              </View>
            )}
          </View>
        )}

        {currentTab === 'Evolucao' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Evolução & Metas</Text>

            {evolutionError ? <Text accessibilityRole="alert" style={styles.errorAlertText}>{evolutionError}</Text> : evolutionData?.clinical_records && evolutionData.clinical_records.length > 0 ? (
              evolutionData.clinical_records.map((c) => (
                <View key={c.id} style={styles.card}>
                  <Text style={styles.cardTitle}>📊 Medição Clínica Oficial • {c.date}</Text>
                  {c.weight_kg && <Text style={styles.mealItem}>Peso: {c.weight_kg} kg</Text>}
                  {c.bmi && <Text style={styles.mealItem}>IMC: {c.bmi}</Text>}
                  <Text style={styles.metaLabel}>Aferido presencialmente pelo profissional</Text>
                </View>
              ))
            ) : (
              <View style={styles.emptyContainer}>
                <Text style={styles.emptyTitle}>Sem avaliações clínicas cadastradas</Text>
              </View>
            )}

            {evolutionData?.self_reports && evolutionData.self_reports.length > 0 && (
              evolutionData.self_reports.map((s) => (
                <View key={s.id} style={styles.card}>
                  <Text style={styles.cardTitle}>📝 Autorrelato • {s.date}</Text>
                  <Text style={styles.mealItem}>
                    {s.metric_type}: {s.value} {s.unit}
                  </Text>
                  {s.notes && <Text style={styles.metaLabel}>{s.notes}</Text>}
                  <Text style={styles.metaLabel}>Origem: Autorrelato do paciente</Text>
                </View>
              ))
            )}
          </View>
        )}

        {currentTab === 'Perfil' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Perfil & Privacidade</Text>
            <View style={styles.card}>
              <Text style={styles.cardTitle}>{patientName}</Text>
              <Text style={styles.mealItem}>Paciente: {session.patient.email}</Text>
              {logoutError && <Text accessibilityRole="alert" style={styles.errorAlertText}>{logoutError}</Text>}
              <TouchableOpacity
                style={[styles.copperButton, { marginTop: 16 }]}
                onPress={async () => {
                  setLoggingOut(true);
                  setLogoutError(null);
                  try {
                    await ApiService.logout();
                    onLogout();
                  } catch (_) {
                    setLogoutError('Não foi possível revogar a sessão no servidor. Verifique sua conexão e tente sair novamente.');
                  } finally { setLoggingOut(false); }
                }}
                accessibilityRole="button"
                disabled={loggingOut}
                accessibilityLabel="Encerrar sessão e revogar token"
              >
                <Text style={styles.copperButtonText}>{loggingOut ? 'Encerrando sessão...' : 'Encerrar Sessão (Logout)'}</Text>
              </TouchableOpacity>
            </View>
          </View>
        )}
      </ScrollView>

      {/* Barra de Navegação Inferior WCAG AA (NI2-140) */}
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
    backgroundColor: '#FAF7F2', // Sand Premium
  },
  centerContainer: {
    flex: 1,
    backgroundColor: '#FAF7F2',
    justifyContent: 'center',
    alignItems: 'center',
    padding: 24,
  },
  loadingText: {
    marginTop: 14,
    fontSize: 15,
    color: '#0F2747',
    fontWeight: '600',
  },
  syncBanner: {
    backgroundColor: '#EBF3FA',
    paddingVertical: 8,
    paddingHorizontal: 16,
    alignItems: 'center',
    borderBottomWidth: 1,
    borderBottomColor: '#D0E1F2',
  },
  syncBannerOffline: {
    backgroundColor: '#FEF3F2',
    borderBottomColor: '#FECDCA',
  },
  syncText: {
    fontSize: 12,
    fontWeight: '600',
    color: '#0F2747',
  },
  syncTextOffline: {
    color: '#B42318',
  },
  errorAlert: {
    backgroundColor: '#FEF3F2',
    padding: 12,
    marginHorizontal: 16,
    marginTop: 10,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#FDA29B',
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  errorAlertText: {
    fontSize: 13,
    color: '#B42318',
    flex: 1,
    fontWeight: '500',
  },
  retryButton: {
    backgroundColor: '#B42318',
    paddingVertical: 6,
    paddingHorizontal: 10,
    borderRadius: 6,
    marginLeft: 8,
    minHeight: 44,
    justifyContent: 'center',
  },
  retryButtonText: {
    color: '#FFFFFF',
    fontSize: 12,
    fontWeight: '700',
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
  telehealthLink: {
    fontSize: 13,
    color: '#027A48',
    fontWeight: '600',
    marginBottom: 10,
  },
  waterValue: {
    fontSize: 22,
    fontWeight: '700',
    color: '#0F2747',
    marginBottom: 8,
  },
  progressBarBackground: {
    height: 8,
    backgroundColor: '#E5E7EB',
    borderRadius: 4,
    overflow: 'hidden',
    marginBottom: 12,
  },
  progressBarFill: {
    height: '100%',
    backgroundColor: '#0F2747',
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
    minHeight: 44, // WCAG alvo de toque
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
  mealItemRow: {
    marginBottom: 6,
  },
  mealItem: {
    fontSize: 14,
    color: '#1F2937',
    lineHeight: 20,
  },
  substitutionText: {
    fontSize: 12,
    color: '#E67A3F',
    marginTop: 2,
    fontStyle: 'italic',
  },
  emptyItemsText: {
    fontSize: 13,
    color: '#9CA3AF',
    fontStyle: 'italic',
    marginBottom: 8,
  },
  actionRow: {
    flexDirection: 'row',
    justifyContent: 'flex-end',
    alignItems: 'center',
    marginTop: 12,
    paddingTop: 10,
    borderTopWidth: 1,
    borderTopColor: '#F3F4F6',
  },
  registerMealButton: {
    backgroundColor: '#0F2747',
    paddingVertical: 8,
    paddingHorizontal: 14,
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
  emptyContainer: {
    backgroundColor: '#FFFFFF',
    borderRadius: 14,
    padding: 24,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: '#E5E7EB',
    marginBottom: 16,
  },
  emptyTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: '#0F2747',
    marginBottom: 6,
    textAlign: 'center',
  },
  emptyDescription: {
    fontSize: 13,
    color: '#667085',
    textAlign: 'center',
    lineHeight: 18,
  },
  emptyCard: {
    paddingVertical: 8,
  },
  emptyCardText: {
    fontSize: 13,
    color: '#667085',
    fontStyle: 'italic',
  },
  diaryStatus: {
    fontSize: 12,
    fontWeight: '600',
    color: '#027A48',
    marginTop: 6,
  },
  feedbackBox: {
    backgroundColor: '#F0F9FF',
    padding: 10,
    borderRadius: 6,
    marginTop: 8,
    borderLeftWidth: 3,
    borderLeftColor: '#0284C7',
  },
  feedbackTitle: {
    fontSize: 12,
    fontWeight: '700',
    color: '#0369A1',
    marginBottom: 2,
  },
  feedbackText: {
    fontSize: 13,
    color: '#0F2747',
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
