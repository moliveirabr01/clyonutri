import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
  StyleSheet,
  Text,
  View,
  ScrollView,
  TouchableOpacity,
  SafeAreaView,
  ActivityIndicator,
  RefreshControl,
  TextInput,
  Alert,
  Linking,
  Platform,
} from 'react-native';
import { displayDateTime, displayLocalDateTime, tabLabels } from '../utils/presentation';
import EvolutionChart from '../components/EvolutionChart';
import NextAppointmentCard from '../components/NextAppointmentCard';
import BrandLogo from '../components/BrandLogo';
import { tokens as t } from '../theme/tokens';
import {
  ApiService,
  ApiError,
  PatientAuthData,
  PatientHomeData,
  FoodDiaryEntry,
  EvolutionData,
  PatientRecipePrescription,
  PatientRecipeDetails,
  ChatMessage,
  PatientPrivacyData,
} from '../services/api';

type ResourceState = { loading: boolean; error: string | null; updatedAt: string | null };
const initialResource: ResourceState = { loading: false, error: null, updatedAt: null };
function resourceError(error: unknown): string {
  if (error instanceof ApiError) return error.message + (error.requestId ? ` Código de atendimento: ${error.requestId}.` : '');
  return 'Não foi possível carregar os dados. Tente novamente.';
}
function ResourceNotice({ state, retry, name }: { state: ResourceState; retry: () => void; name: string }) {
  return <View style={{ marginBottom: t.space }}>
    {state.loading && <ActivityIndicator accessibilityLabel={`Atualizando ${name}`} />}
    {state.updatedAt && <Text style={{ color: t.muted }} accessibilityLiveRegion="polite">{state.error ? 'Dados desatualizados. ' : ''}Última atualização: {state.updatedAt}</Text>}
    {state.error && <Text style={{ color: t.error }} accessibilityRole="alert" accessibilityLiveRegion="assertive">{state.error}</Text>}
    <TouchableOpacity accessibilityRole="button" accessibilityLabel={`${state.error ? 'Tentar novamente' : 'Atualizar'} ${name}`} onPress={retry} disabled={state.loading} accessibilityState={{ busy: state.loading, disabled: state.loading }} style={{ minHeight: 44, justifyContent: 'center' }}><Text style={{ color: t.navy }}>{state.error ? 'Tentar novamente' : 'Atualizar'}</Text></TouchableOpacity>
  </View>;
}

export type NavigationTab = 'Hoje' | 'Plano' | 'Diario' | 'Chat' | 'Evolucao' | 'Perfil';

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
  const [diaryState, setDiaryState] = useState<ResourceState>(initialResource);
  const [evolutionState, setEvolutionState] = useState<ResourceState>(initialResource);
  const [evolutionPeriod, setEvolutionPeriod] = useState<'30' | '90' | '365' | 'all'>('365');
  const [metricType, setMetricType] = useState<string | null>(null);
  const [metricValue, setMetricValue] = useState('');
  const [metricNotes, setMetricNotes] = useState('');
  const [metricError, setMetricError] = useState<string | null>(null);
  const [metricFeedback, setMetricFeedback] = useState<string | null>(null);
  const [metricSaving, setMetricSaving] = useState(false);
  const [homeUpdatedAt, setHomeUpdatedAt] = useState<string | null>(null);
  const [logoutError, setLogoutError] = useState<string | null>(null);
  const [loggingOut, setLoggingOut] = useState(false);
  const [privacy, setPrivacy] = useState<PatientPrivacyData | null>(null);
  const [privacyError, setPrivacyError] = useState<string | null>(null);
  const [privacyConfirm, setPrivacyConfirm] = useState<'export' | 'deletion' | null>(null);
  const [privacyFeedback, setPrivacyFeedback] = useState<string | null>(null);
  const [privacyBusy, setPrivacyBusy] = useState(false);

  const loadPrivacy = async () => {
    setPrivacyError(null);
    try { setPrivacy(await ApiService.getPrivacy()); }
    catch (_) { setPrivacyError('Não foi possível carregar seus consentimentos e pedidos. Tente novamente.'); }
  };
  useEffect(() => { if (currentTab === 'Perfil') { void loadPrivacy(); } }, [currentTab]);
  const submitPrivacyRequest = async (type: 'export' | 'deletion') => {
    setPrivacyBusy(true); setPrivacyFeedback(null); setPrivacyError(null);
    try {
      const r = await ApiService.createPrivacyRequest(type);
      setPrivacyFeedback(r.already_open
        ? 'Você já tem um pedido igual em andamento. O prazo de resposta continua o mesmo.'
        : `Pedido registrado. Prazo de resposta: até ${displayDateTime(r.deadline_at)}.`);
      setPrivacyConfirm(null);
      await loadPrivacy();
    } catch (_) {
      setPrivacyError('Não foi possível registrar o pedido. Verifique sua conexão e tente de novo.');
    } finally { setPrivacyBusy(false); }
  };
  const [actionLoading, setActionLoading] = useState<boolean>(false);

  const [recipes, setRecipes] = useState<PatientRecipePrescription[]>([]);
  const [recipesState, setRecipesState] = useState<ResourceState>(initialResource);
  const [recipeDetails, setRecipeDetails] = useState<PatientRecipeDetails | null>(null);
  const [selectedPrescription, setSelectedPrescription] = useState<number | null>(null);
  const [recipeUnavailable, setRecipeUnavailable] = useState(false);
  const [mealDraft, setMealDraft] = useState<{ name: string; planned: string } | null>(null);
  const [mealMode, setMealMode] = useState<'planned' | 'different'>('planned');
  const [mealDescription, setMealDescription] = useState('');
  const [mealFeedback, setMealFeedback] = useState<string | null>(null);
  const [mealError, setMealError] = useState<string | null>(null);
  const [recipeDetailState, setRecipeDetailState] = useState<ResourceState>(initialResource);

  // Estado e métodos do Chat Seguro com Nutricionista
  const [chatMessages, setChatMessages] = useState<ChatMessage[]>([]);
  const [chatConvId, setChatConvId] = useState<number | null>(null);
  const [chatProfessional, setChatProfessional] = useState<{ id: number; name: string; crn?: string | null } | null>(null);
  const [chatInputText, setChatInputText] = useState<string>('');
  const [chatSending, setChatSending] = useState<boolean>(false);
  const [chatState, setChatState] = useState<ResourceState>(initialResource);
  const [chatConnected, setChatConnected] = useState<boolean>(true);
  const chatScrollRef = useRef<ScrollView>(null);

  const loadChat = useCallback(async (afterId?: number) => {
    try {
      const data = await ApiService.getChatMessages(afterId);
      if (data.conversation_id) setChatConvId(data.conversation_id);
      if (data.professional) setChatProfessional(data.professional);

      setChatConnected(true);

      if (afterId && afterId > 0) {
        if (data.messages && data.messages.length > 0) {
          setChatMessages(prev => {
            const existingIds = new Set(prev.map(m => m.id));
            const newMsgs = data.messages.filter(m => !existingIds.has(m.id));
            if (newMsgs.length === 0) return prev;
            return [...prev, ...newMsgs];
          });
          if (data.conversation_id) {
            ApiService.markChatAsRead(data.conversation_id).catch(() => {});
          }
        }
      } else {
        setChatMessages(data.messages || []);
        setChatState({ loading: false, error: null, updatedAt: new Date().toLocaleTimeString('pt-BR') });
        if (data.conversation_id && data.messages && data.messages.length > 0) {
          ApiService.markChatAsRead(data.conversation_id).catch(() => {});
        }
      }
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'auth') return;
      setChatConnected(false);
      setChatState(prev => ({ ...prev, loading: false, error: resourceError(err) }));
    }
  }, []);

  useEffect(() => {
    if (currentTab !== 'Chat') return;
    setChatState(prev => ({ ...prev, loading: true }));
    loadChat();

    const interval = setInterval(() => {
      setChatMessages(current => {
        const lastId = current.length > 0 ? current[current.length - 1].id : 0;
        loadChat(lastId);
        return current;
      });
    }, 3000);

    return () => clearInterval(interval);
  }, [currentTab, loadChat]);

  const handleSendMessage = async () => {
    const text = chatInputText.trim();
    if (!text || chatSending) return;

    setChatSending(true);
    try {
      const res = await ApiService.sendChatMessage(text, chatConvId ?? undefined);
      if (res.success && res.message) {
        setChatMessages(prev => [...prev, res.message]);
        if (res.message.conversation_id && !chatConvId) {
          setChatConvId(res.message.conversation_id);
        }
        setChatInputText('');
        setTimeout(() => chatScrollRef.current?.scrollToEnd({ animated: true }), 100);
      }
    } catch (err) {
      Alert.alert('Erro ao enviar', err instanceof Error ? err.message : 'Não foi possível enviar a mensagem.');
    } finally {
      setChatSending(false);
    }
  };
  const refreshRecipes = useCallback(async () => {
    setRecipesState(prev => ({ ...prev, loading: true }));
    try {
      setRecipes(await ApiService.getRecipes());
      setRecipesState({ loading: false, error: null, updatedAt: new Date().toLocaleString('pt-BR') });
    } catch (error) {
      setRecipesState(prev => ({ ...prev, loading: false, error: resourceError(error) }));
    }
  }, []);
  const openRecipe = async (prescription: PatientRecipePrescription) => {
    setSelectedPrescription(prescription.id); setRecipeDetails(null); setRecipeUnavailable(false);
    setRecipeDetailState({ loading: true, error: null, updatedAt: null });
    try {
      setRecipeDetails(await ApiService.getRecipe(prescription.recipe_id, prescription.id));
      setRecipeDetailState({ loading: false, error: null, updatedAt: null });
    } catch (error) {
      setRecipeUnavailable(error instanceof ApiError && error.kind === 'unavailable');
      setRecipeDetailState({ loading: false, error: resourceError(error), updatedAt: null });
    }
  };

  const refreshDiary = useCallback(async () => {
    setDiaryState(prev => ({ ...prev, loading: true }));
    try {
      const diary = await ApiService.getFoodDiary();
      setDiaryEntries(diary.entries);
      setDiaryState({ loading: false, error: null, updatedAt: new Date().toLocaleString('pt-BR') });
    } catch (error) {
      if (error instanceof ApiError && error.kind === 'auth') return;
      setDiaryState(prev => ({ ...prev, loading: false, error: resourceError(error) }));
    }
  }, []);
  const refreshEvolution = useCallback(async () => {
    setEvolutionState(prev => ({ ...prev, loading: true }));
    try {
      const from = evolutionPeriod === 'all' ? null : new Date(Date.now() - Number(evolutionPeriod) * 86400000).toISOString().slice(0, 10);
      const evolution = await ApiService.getEvolution(from);
      setEvolutionData(evolution);
      setEvolutionState({ loading: false, error: null, updatedAt: new Date().toLocaleString('pt-BR') });
    } catch (error) {
      if (error instanceof ApiError && error.kind === 'auth') return;
      setEvolutionState(prev => ({ ...prev, loading: false, error: resourceError(error) }));
    }
  }, [evolutionPeriod]);
  useEffect(() => { void refreshEvolution(); }, [evolutionPeriod]);
  const submitSelfMetric = async () => {
    const metric = evolutionData?.enabled_metrics?.find((m) => m.type === metricType);
    setMetricError(null); setMetricFeedback(null);
    if (!metric) { setMetricError('Escolha o que você quer registrar.'); return; }
    const value = Number(metricValue.replace(',', '.'));
    if (!metricValue.trim() || !Number.isFinite(value)) { setMetricError(`Informe ${metric.label.toLowerCase()} em ${metric.unit}.`); return; }
    if (value < metric.min || value > metric.max) { setMetricError(`${metric.label} deve estar entre ${metric.min} e ${metric.max} ${metric.unit}. Confira o valor digitado.`); return; }
    setMetricSaving(true);
    try {
      const saved = await ApiService.logSelfMetric({ metric_type: metric.type, value, unit: metric.unit, notes: metricNotes.trim() || undefined });
      setMetricFeedback(`${saved.label} registrado: ${saved.value.toLocaleString('pt-BR')} ${saved.unit}.`);
      setMetricValue(''); setMetricNotes('');
      await refreshEvolution();
    } catch (error) {
      setMetricError(error instanceof ApiError ? error.message : 'Não foi possível registrar. Tente novamente.');
    } finally { setMetricSaving(false); }
  };
  const fetchAllData = useCallback(async () => {
    setErrorMessage(null);
    const home = async () => {
      try {
        setHomeData(await ApiService.getHome());
        setHomeUpdatedAt(new Date().toLocaleString('pt-BR'));
        setIsOffline(false);
      } catch (error) {
        if (error instanceof ApiError && error.kind === 'auth') return;
        setIsOffline(error instanceof ApiError && error.kind === 'offline');
        setErrorMessage(resourceError(error));
      } finally { setLoading(false); }
    };
    await Promise.all([home(), refreshDiary(), refreshEvolution(), refreshRecipes()]);
    setRefreshing(false);
  }, [refreshDiary, refreshEvolution, refreshRecipes]);

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
      setIsOffline(err instanceof ApiError && err.kind === 'offline');
    } finally {
      setActionLoading(false);
    }
  };

  // NI2-137: o card da consulta recarrega só a home depois de cada ação (estado real do servidor).
  const reloadHome = useCallback(async () => {
    try {
      setHomeData(await ApiService.getHome());
      setHomeUpdatedAt(new Date().toLocaleString('pt-BR'));
      setIsOffline(false);
    } catch (error) {
      if (error instanceof ApiError && error.kind === 'auth') return;
      setIsOffline(error instanceof ApiError && error.kind === 'offline');
      setErrorMessage(resourceError(error));
    }
  }, []);

  // Teleconsulta e Diário Alimentar (MOB-UX-003, MOB-UX-004, MOB-UX-012)
  const [registeringMeal, setRegisteringMeal] = useState<string | null>(null);

  // NI2-137 (B6): o link leva um bilhete de acesso; nunca é mostrado na tela, nem em mensagem de erro.
  const [telehealthError, setTelehealthError] = useState<string | null>(null);
  const handleOpenTelehealth = async (url: string) => {
    setTelehealthError(null);
    try {
      const trimmed = url.trim();
      if (await Linking.canOpenURL(trimmed)) {
        await Linking.openURL(trimmed);
      } else {
        setTelehealthError('Não foi possível abrir a sala neste aparelho. Atualize a tela e tente de novo, ou entre pelo link do e-mail.');
      }
    } catch {
      setTelehealthError('Não foi possível abrir a sala neste aparelho. Atualize a tela e tente de novo, ou entre pelo link do e-mail.');
    }
  };

  const mealWriteBusy = useRef(false);
  const pendingMealWrites = useRef<Record<string, { key: string; consumedAt: string; description: string }>>({});
  const handleRegisterMeal = async (mealName: string, customDescription?: string) => {
    if (mealWriteBusy.current) return;
    mealWriteBusy.current = true;
    setRegisteringMeal(mealName);
    setMealError(null);
    try {
      const pending = pendingMealWrites.current[mealName] ?? {
        key: `meal_${Date.now()}_${Math.random().toString(36).slice(2)}`,
        consumedAt: new Date().toISOString(),
        description: customDescription || 'Consumo informado pelo paciente',
      };
      pendingMealWrites.current[mealName] = pending;
      const entry = await ApiService.logMeal({
        meal_name: mealName,
        description: pending.description,
        consumed_at: pending.consumedAt,
        idempotency_key: pending.key,
      });
      delete pendingMealWrites.current[mealName];
      setDiaryEntries(prev => {
        if (prev.some(e => e.id === entry.id)) return prev;
        return [entry, ...prev];
      });
      setMealFeedback(`${mealName} registrado em ${displayDateTime(entry.consumed_at)}. Você pode conferir no Diário.`);
      setMealError(null);
      setMealDraft(null);
    } catch (err: any) {
      setMealError('Não foi possível confirmar o registro. Seus dados foram preservados. Tente salvar novamente para confirmar o mesmo consumo.');
    } finally {
      mealWriteBusy.current = false;
      setRegisteringMeal(null);
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
            : errorMessage || diaryState.error || evolutionState.error ? 'Atualização parcial. Alguns dados não foram atualizados.' : 'Resumo atualizado com seu consultório'
        }
      >
        <Text style={[styles.syncText, isOffline && styles.syncTextOffline]}>
          {isOffline
            ? '⚠️ Conexão indisponível — dados podem estar desatualizados'
            : errorMessage || diaryState.error || evolutionState.error ? '⚠️ Atualização parcial — confira os avisos em cada aba' : '✓ Resumo atualizado com seu consultório'}
        </Text>
      </View>

      {homeUpdatedAt && <Text accessibilityLiveRegion="polite">{errorMessage ? 'Resumo desatualizado. ' : ''}Última atualização do resumo: {homeUpdatedAt}</Text>}
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
              <BrandLogo compact />
              <Text
                style={styles.greeting}
                accessibilityRole="header"
              >
                Olá, {patientName} 👋
              </Text>
              <Text style={styles.subGreeting}>Acompanhamento nutricional dedicado</Text>
            </View>

            <TouchableOpacity style={styles.card} accessibilityRole="button" onPress={() => setCurrentTab('Plano')}>
              <Text style={styles.cardTitle}>Minhas receitas</Text>
              <Text style={styles.helperText}>Veja as receitas e orientações prescritas pelo seu nutricionista →</Text>
            </TouchableOpacity>

            <TouchableOpacity
              style={[styles.card, { borderColor: '#10B981', backgroundColor: '#F0FDF4' }]}
              accessibilityRole="button"
              onPress={() => setCurrentTab('Chat')}
              accessibilityLabel="Falar com nutricionista no chat seguro"
            >
              <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                <View style={{ flex: 1 }}>
                  <Text style={[styles.cardTitle, { color: '#065F46' }]}>💬 Chat com Nutricionista</Text>
                  <Text style={[styles.helperText, { color: '#047857' }]}>
                    Fale online com seu nutricionista, tire dúvidas sobre o plano e envie mensagens →
                  </Text>
                </View>
                <View style={{ width: 10, height: 10, borderRadius: 5, backgroundColor: '#10B981', marginLeft: 8 }} />
              </View>
            </TouchableOpacity>

            {/* Card da próxima consulta (NI2-137): data com fuso, modalidade, local e ações liberadas pelo consultório */}
            <NextAppointmentCard appointment={nextApp} onChanged={reloadHome} onOpenTelehealth={handleOpenTelehealth} />
            {telehealthError && <Text style={{ color: t.error, marginBottom: t.space }} accessibilityRole="alert" accessibilityLiveRegion="assertive">{telehealthError}</Text>}

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

            {mealFeedback && <Text accessibilityLiveRegion="polite" style={[styles.helperText, { color: t.success }]}>{mealFeedback}</Text>}
            {/* Refeições de Hoje com dados reais do plano ou Empty State (Critério 2) */}
            <Text style={styles.sectionTitle} accessibilityRole="header">Prescrição do Dia</Text>

            {activePlan && activePlan.meals && activePlan.meals.length > 0 ? (
              activePlan.meals.map((meal) => (
                <View key={meal.id} style={styles.mealCard}>
                  <View style={styles.mealHeader}>
                    <Text style={styles.mealName}>{meal.name}</Text>
                    <Text style={styles.mealTime}>{meal.time || '--:--'}</Text>
                  </View>

                  {meal.notes ? <Text style={styles.itemNotesText}>Orientação da refeição: {meal.notes}</Text> : null}
                  {meal.items && meal.items.length > 0 ? (
                    meal.items.map((item) => (
                      <View key={item.id} style={styles.mealItemRow}>
                        <Text style={styles.mealItem}>
                          • {item.quantity} {item.unit} de {item.food_name}
                        </Text>
                        {item.substitutions && item.substitutions.length > 0 && (
                          <View style={styles.substitutionContainer}>
                            <Text style={styles.substitutionHeader}>Opções de substituição:</Text>
                            {item.substitutions.map((s) => (
                              <Text key={s.id} style={styles.substitutionText}>
                                ↳ {s.amount ? `${s.amount} ${s.measure_unit || ''} de ` : ''}{s.food_name}{s.notes ? ` • ${s.notes}` : ''}
                              </Text>
                            ))}
                          </View>
                        )}
                        {item.notes ? (
                          <Text style={styles.itemNotesText}>
                            ℹ️ Orientação: {item.notes}
                          </Text>
                        ) : null}
                      </View>
                    ))
                  ) : (
                    <Text style={styles.emptyItemsText}>Nenhum item cadastrado nesta refeição.</Text>
                  )}

                  {diaryEntries.filter(entry => entry.meal_name === meal.name && new Date(entry.consumed_at).toLocaleDateString() === new Date().toLocaleDateString()).slice(0, 1).map(entry => <Text key={entry.id} style={[styles.helperText, { color: t.success }]}>✓ Consumo registrado em {displayDateTime(entry.consumed_at)}</Text>)}
            {mealDraft?.name === meal.name && <View style={styles.card}>
              <Text style={styles.cardTitle}>Registrar {mealDraft.name}</Text>
              <Text style={styles.helperText}>O que você consumiu? O registro só será enviado ao confirmar.</Text>
              <View accessibilityRole="radiogroup">
                {(['planned', 'different'] as const).map(mode => <TouchableOpacity key={mode} accessibilityRole="radio" aria-checked={mealMode === mode} accessibilityState={{ checked: mealMode === mode }} disabled={registeringMeal !== null || !!mealError} onPress={() => setMealMode(mode)} style={[styles.navyButton, { marginTop: 8, backgroundColor: mealMode === mode ? t.navy : t.background }]}>
                  <Text style={{ color: mealMode === mode ? t.white : t.navy }}>{mode === 'planned' ? 'Conforme o plano' : 'Comi diferente'}</Text>
                </TouchableOpacity>)}
              </View>
              {mealMode === 'planned' ? <Text style={styles.mealItem}>{mealDraft.planned}</Text> : <>
                <Text style={styles.helperText}>Conte o que comeu e a quantidade aproximada *</Text>
                <TextInput accessibilityLabel="O que você comeu e quantidade aproximada" multiline maxLength={1500} editable={registeringMeal === null && !mealError} value={mealDescription} onChangeText={setMealDescription} placeholder="Ex.: um sanduíche de queijo e um copo de água" style={[styles.chatTextInput, { flex: undefined, borderWidth: 1, borderColor: t.border, borderRadius: 8, minHeight: 88, marginTop: 8 }]} />
              </>}
              {mealError && <Text accessibilityRole="alert" style={{ color: t.error }}>{mealError}</Text>}
              <TouchableOpacity accessibilityRole="button" disabled={registeringMeal !== null || (mealMode === 'different' && !mealDescription.trim())} style={[styles.navyButton, { marginTop: 12 }]} onPress={() => handleRegisterMeal(mealDraft.name, mealMode === 'planned' ? `Conforme o plano: ${mealDraft.planned}` : `Comi diferente: ${mealDescription.trim()}`)}>
                <Text style={styles.navyButtonText}>{registeringMeal ? 'Salvando...' : mealError ? 'Tentar confirmar registro' : 'Confirmar consumo'}</Text>
              </TouchableOpacity>
              <TouchableOpacity accessibilityRole="button" disabled={registeringMeal !== null} onPress={() => setMealDraft(null)} style={{ minHeight: 44, justifyContent: 'center' }}><Text style={{ color: t.navy }}>{mealError ? 'Voltar e confirmar depois' : 'Cancelar'}</Text></TouchableOpacity>
            </View>}
                  <View style={styles.actionRow}>
                    <TouchableOpacity
                      style={[styles.registerMealButton, registeringMeal === meal.name && styles.registerMealButtonDisabled]}
                      onPress={() => {
                        const pending = pendingMealWrites.current[meal.name];
                        setMealDraft({ name: meal.name, planned: meal.items.map(item => `${item.quantity} ${item.unit} de ${item.food_name}`).join('; ') });
                        setMealMode(pending?.description.startsWith('Comi diferente:') ? 'different' : 'planned');
                        setMealDescription(pending?.description.replace(/^Comi diferente: /, '') || '');
                        setMealError(pending ? 'Este consumo aguarda confirmação. Tente salvar novamente sem alterar os dados.' : null);
                      }}
                      disabled={registeringMeal !== null || mealDraft !== null}
                      accessibilityRole="button"
                      accessibilityLabel={`Registrar consumo da refeição ${meal.name}`}
                    >
                      <Text style={styles.registerMealText}>
                        {registeringMeal === meal.name ? 'Registrando...' : diaryEntries.some(entry => entry.meal_name === meal.name && new Date(entry.consumed_at).toLocaleDateString() === new Date().toLocaleDateString()) ? 'Registrar outro consumo' : 'Registrar Consumo'}
                      </Text>
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
                  {activePlan.title || 'Plano vigente'} • Disponibilizado pelo nutricionista
                  {activePlan.version ? ` • Versão ${activePlan.version}` : ''}
                  {activePlan.published_at ? ` • Publicado em ${displayDateTime(activePlan.published_at)}` : ''}
                </Text>
                {activePlan.notes && (
                  <View style={styles.card}>
                    <Text style={styles.cardTitle}>Orientações Gerais</Text>
                    <Text style={styles.mealItem}>{activePlan.notes}</Text>
                    <TouchableOpacity accessibilityRole="button" style={{ minHeight: 44, justifyContent: 'center' }} onPress={() => { setChatInputText('Olá! Pode esclarecer as orientações do meu plano alimentar?'); setCurrentTab('Chat'); }}><Text style={{ color: t.navy }}>Esclarecer uma orientação com o nutricionista</Text></TouchableOpacity>
                  </View>
                )}
                {activePlan.meals?.map((m) => (
                  <View key={m.id} style={styles.card}>
                    <Text style={styles.cardTitle}>{m.name} ({m.time})</Text>
                    {m.notes ? <Text style={styles.helperText}>Orientação da refeição: {m.notes}</Text> : null}
                    {m.items?.map((it) => (
                      <View key={it.id} style={styles.mealItemRow}>
                        <Text style={styles.mealItem}>
                          • {it.food_name} ({it.quantity} {it.unit})
                        </Text>
                        {it.substitutions && it.substitutions.length > 0 && (
                          <View style={styles.substitutionContainer}>
                            <Text style={styles.substitutionHeader}>Opções de substituição:</Text>
                            {it.substitutions.map((s) => (
                              <Text key={s.id} style={styles.substitutionText}>
                                ↳ {s.amount ? `${s.amount} ${s.measure_unit || ''} de ` : ''}{s.food_name}{s.notes ? ` • ${s.notes}` : ''}
                              </Text>
                            ))}
                          </View>
                        )}
                        {it.notes ? (
                          <Text style={styles.itemNotesText}>
                            ℹ️ Orientação: {it.notes}
                          </Text>
                        ) : null}
                      </View>
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

            <Text style={styles.sectionTitle} accessibilityRole="header">Minhas receitas</Text>
            <ResourceNotice state={recipesState} retry={refreshRecipes} name="receitas" />
            {!recipesState.loading && !recipesState.error && recipes.length === 0 && <Text style={styles.helperText}>Seu nutricionista ainda não prescreveu receitas para você.</Text>}
            {recipes.map(recipe => <View key={recipe.id} style={styles.card}>
              <Text style={styles.cardTitle}>{recipe.recipe_title}</Text>
              {recipe.meal_name && <Text style={styles.helperText}>{recipe.meal_name}</Text>}
              <Text style={styles.mealItem}>{Number(recipe.prescribed_servings).toLocaleString('pt-BR')} porção(ões) • {Number(recipe.scaled_energy_kcal).toLocaleString('pt-BR')} kcal</Text>
              {recipe.recipe_summary && <Text style={styles.helperText}>{recipe.recipe_summary}</Text>}
              {recipe.instructions_override && <Text style={styles.mealItem}>Orientação do nutricionista: {recipe.instructions_override}</Text>}
              {!(selectedPrescription === recipe.id && recipeUnavailable) && <TouchableOpacity accessibilityRole="button" disabled={recipeDetailState.loading} style={styles.retryButton} onPress={() => openRecipe(recipe)}>
                <Text style={styles.retryButtonText}>Ver ingredientes e preparo</Text>
              </TouchableOpacity>}
              {selectedPrescription === recipe.id && <>
                {recipeDetailState.loading && <ActivityIndicator accessibilityLabel="Carregando receita" />}
                {recipeDetailState.error && <>
                  <Text accessibilityRole="alert" style={{ color: t.error }}>{recipeDetailState.error}{!recipeUnavailable ? ' Tente abrir novamente.' : ''}</Text>
                  {recipeUnavailable && <TouchableOpacity accessibilityRole="button" style={styles.navyButton} onPress={() => { setChatInputText(`Olá! A receita “${recipe.recipe_title}” está indisponível no aplicativo. Pode disponibilizar uma nova prescrição?`); setCurrentTab('Chat'); }}><Text style={styles.navyButtonText}>Solicitar nova prescrição no chat</Text></TouchableOpacity>}
                </>}
                {recipeDetails && <>
                  <Text style={styles.cardTitle}>
                    Sua porção • Quantidades da sua prescrição ({recipe.prescribed_servings} porção(ões))
                  </Text>
                  {recipeDetails.ingredients.map(ingredient => {
                    const ratio = Number(recipe.prescribed_servings) / (Number(recipeDetails.servings) || 1);
                    const scaledAmount = Math.round(Number(ingredient.amount) * ratio * 100) / 100;
                    return (
                      <View key={ingredient.id} style={{ marginBottom: 4 }}>
                        <Text style={styles.mealItem}>
                          • <Text style={{ fontWeight: '700' }}>{scaledAmount} {ingredient.measure_unit}</Text> de {ingredient.food_name}
                          {ratio !== 1 ? (
                            <Text style={styles.helperText}> (Receita base inteira: {ingredient.amount} {ingredient.measure_unit})</Text>
                          ) : null}
                        </Text>
                      </View>
                    );
                  })}
                  <Text style={styles.cardTitle}>Preparo da receita base • {Number(recipeDetails.servings).toLocaleString('pt-BR')} porções</Text>
                  <Text style={styles.helperText}>O preparo original abaixo usa a receita inteira. Após preparar, separe sua prescrição de {Number(recipe.prescribed_servings).toLocaleString('pt-BR')} porção(ões). As quantidades acima correspondem apenas à sua porção.</Text>
                  <Text style={styles.mealItem}>{recipeDetails.instructions}</Text>
                </>}
              </>}
            </View>)}


          </View>
        )}

        {currentTab === 'Diario' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Diário Alimentar</Text>
            <Text style={styles.helperText}>Histórico com distinção clara entre planejado e realizado</Text>

            {<ResourceNotice state={diaryState} retry={refreshDiary} name="diário" />}
            {diaryEntries.length > 0 ? (
              diaryEntries.map((entry) => (
                <View key={entry.id} style={styles.card}>
                  <Text style={styles.cardTitle}>
                    {entry.meal_name} • {displayDateTime(entry.consumed_at)}
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
            ) : !diaryState.error && !diaryState.loading ? (
              <View style={styles.emptyContainer}>
                <Text style={styles.emptyTitle}>Nenhum registro ainda hoje</Text>
                <Text style={styles.emptyDescription}>
                  Clique em &ldquo;Registrar Consumo&rdquo; na aba Hoje para anotar suas refeições realizadas.
                </Text>
              </View>
            ) : null}
          </View>
        )}

        {currentTab === 'Chat' && (
          <View style={styles.tabContent}>
            {/* Header com profissional e status online */}
            <View style={styles.chatHeaderCard}>
              <View style={styles.chatHeaderInfo}>
                <View style={styles.chatAvatar}>
                  <Text style={styles.chatAvatarText}>
                    {chatProfessional?.name ? chatProfessional.name.charAt(0).toUpperCase() : 'N'}
                  </Text>
                </View>
                <View style={{ flex: 1, marginLeft: 12 }}>
                  <Text style={styles.chatNutriName} accessibilityRole="header">
                    {chatProfessional?.name || 'Seu Nutricionista'}
                  </Text>
                  <View style={styles.chatOnlineRow}>
                    <View style={[styles.chatOnlineDot, !chatConnected && { backgroundColor: '#94A3B8' }]} />
                    <Text style={styles.chatOnlineText}>
                      {chatConnected ? 'Conectado ao consultório' : 'Desconectado'} • {chatProfessional?.crn || 'Atendimento Clínico'}
                    </Text>
                  </View>
                </View>
              </View>
              <Text style={styles.chatSecurityBadge}>🔒 Canal Clínico Protegido (HTTPS/TLS) • Não substitui urgência</Text>
            </View>

            {chatState.error ? (
              <View style={styles.chatErrorBanner} accessible={true} accessibilityRole="alert">
                <Text style={styles.chatErrorText}>Erro de conexão: {chatState.error}</Text>
                <TouchableOpacity
                  style={styles.chatRetryButton}
                  onPress={() => { setChatState(prev => ({ ...prev, loading: true, error: null })); loadChat(); }}
                  accessibilityRole="button"
                  accessibilityLabel="Tentar reconectar ao chat"
                >
                  <Text style={styles.chatRetryButtonText}>Reconectar</Text>
                </TouchableOpacity>
              </View>
            ) : null}

            {chatState.loading && chatMessages.length === 0 ? (
              <ActivityIndicator size="large" color="#0F2747" style={{ marginVertical: 32 }} />
            ) : null}

            {/* Mensagens do Chat */}
            <View style={styles.chatMessagesCard}>
              {chatMessages.length === 0 && !chatState.loading ? (
                <View style={styles.chatEmptyState}>
                  <Text style={styles.chatEmptyIcon}>💬</Text>
                  <Text style={styles.chatEmptyTitle}>Converse com seu nutricionista</Text>
                  <Text style={styles.chatEmptyDescription}>
                    Tire dúvidas sobre o plano alimentar, relate sintomas ou compartilhe sua evolução em tempo real.
                  </Text>
                </View>
              ) : (
                chatMessages.map((m) => {
                  const isPatient = m.sender_type === 'patient';
                  return (
                    <View
                      key={m.id}
                      style={[
                        styles.chatBubble,
                        isPatient ? styles.chatBubblePatient : styles.chatBubbleProfessional,
                      ]}
                      accessible={true}
                      accessibilityLabel={`${isPatient ? 'Você' : 'Nutricionista'}: ${m.message}`}
                    >
                      {!isPatient && (
                        <Text style={styles.chatSenderLabel}>
                          {chatProfessional?.name || 'Nutricionista'}
                        </Text>
                      )}
                      <Text
                        style={[
                          styles.chatBubbleText,
                          isPatient ? styles.chatBubbleTextPatient : styles.chatBubbleTextProfessional,
                        ]}
                      >
                        {m.message}
                      </Text>
                      <View style={styles.chatBubbleFooter}>
                        <Text
                          style={[
                            styles.chatTimeText,
                            isPatient ? styles.chatTimePatient : styles.chatTimeProfessional,
                          ]}
                        >
                          {m.created_at ? m.created_at.substring(11, 16) : ''}
                        </Text>
                        {isPatient && (
                          <Text style={styles.chatStatusCheck}>
                            {m.status === 'read' ? '✓✓' : '✓'}
                          </Text>
                        )}
                      </View>
                    </View>
                  );
                })
              )}
            </View>

            {/* Barra de Entrada de Mensagem */}
            <View style={styles.chatInputCard}>
              <TextInput
                style={styles.chatTextInput}
                placeholder="Digite sua mensagem para o nutricionista..."
                placeholderTextColor="#94A3B8"
                value={chatInputText}
                onChangeText={setChatInputText}
                multiline
                maxLength={1000}
                editable={!chatSending}
                accessibilityLabel="Campo de texto para mensagem ao nutricionista"
              />
              <TouchableOpacity
                style={[
                  styles.chatSendBtn,
                  (!chatInputText.trim() || chatSending) && styles.chatSendBtnDisabled,
                ]}
                onPress={handleSendMessage}
                disabled={!chatInputText.trim() || chatSending}
                accessibilityRole="button"
                accessibilityLabel="Enviar mensagem para o nutricionista"
              >
                {chatSending ? (
                  <ActivityIndicator size="small" color="#FFFFFF" />
                ) : (
                  <Text style={styles.chatSendBtnText}>Enviar</Text>
                )}
              </TouchableOpacity>
            </View>
          </View>
        )}

        {currentTab === 'Evolucao' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Evolução e metas</Text>

            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 12 }} accessibilityRole="radiogroup" accessibilityLabel="Período">
              {([['30', '30 dias'], ['90', '3 meses'], ['365', '12 meses'], ['all', 'Tudo']] as const).map(([key, label]) => (
                <TouchableOpacity key={key} onPress={() => setEvolutionPeriod(key)} accessibilityRole="radio" accessibilityState={{ checked: evolutionPeriod === key }} aria-checked={evolutionPeriod === key}
                  style={{ paddingVertical: 8, paddingHorizontal: 12, borderRadius: 16, borderWidth: 1, borderColor: t.navy, backgroundColor: evolutionPeriod === key ? t.navy : t.white, minHeight: 36 }}>
                  <Text style={{ color: evolutionPeriod === key ? t.white : t.navy, fontWeight: '600' }}>{label}</Text>
                </TouchableOpacity>
              ))}
            </View>

            {<ResourceNotice state={evolutionState} retry={refreshEvolution} name="evolução" />}

            {(evolutionData?.goals ?? []).map((g) => (
              <View key={'g' + g.id} style={styles.card} accessible accessibilityLabel={`Meta: ${g.direction_label} ${g.label.toLowerCase()} para ${g.target_value.toLocaleString('pt-BR')} ${g.unit}${g.target_date ? ' até ' + displayLocalDateTime(g.target_date, false) : ''}.`}>
                <Text style={styles.cardTitle}>Meta: {g.direction_label} {g.label.toLowerCase()} · {g.target_value.toLocaleString('pt-BR')} {g.unit}</Text>
                {g.target_date && <Text style={styles.mealItem}>Até {displayLocalDateTime(g.target_date, false)}</Text>}
                {g.message_for_patient && <Text style={styles.mealItem}>{g.message_for_patient}</Text>}
                <Text style={styles.metaLabel}>Último registro do nutricionista: {g.latest_clinical ? `${g.latest_clinical.value.toLocaleString('pt-BR')} ${g.unit} em ${displayLocalDateTime(g.latest_clinical.date)}` : 'nenhum'}</Text>
                <Text style={styles.metaLabel}>Seu último registro: {g.latest_self_report ? `${g.latest_self_report.value.toLocaleString('pt-BR')} ${g.unit} em ${displayLocalDateTime(g.latest_self_report.date)}` : 'nenhum'}</Text>
              </View>
            ))}

            {(evolutionData?.series ?? []).map((sr) => {
              const goal = evolutionData?.goals?.find((g) => g.metric_type === sr.type)?.target_value ?? null;
              return (
                <View key={'s' + sr.type} style={styles.card}>
                  <EvolutionChart series={sr} goal={goal} />
                </View>
              );
            })}

            {evolutionData && (evolutionData.enabled_metrics ?? []).length > 0 && (
              <View style={styles.card}>
                <Text style={styles.cardTitle} accessibilityRole="header">Registrar uma medida</Text>
                <Text style={styles.helperText}>Seu registro aparece como "Você" e não substitui a avaliação do seu nutricionista.</Text>
                <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginVertical: 8 }} accessibilityRole="radiogroup" accessibilityLabel="Medida">
                  {(evolutionData.enabled_metrics ?? []).map((m) => (
                    <TouchableOpacity key={m.type} onPress={() => { setMetricType(m.type); setMetricError(null); }} accessibilityRole="radio" accessibilityState={{ checked: metricType === m.type }} aria-checked={metricType === m.type}
                      style={{ paddingVertical: 8, paddingHorizontal: 12, borderRadius: 16, borderWidth: 1, borderColor: t.copper, backgroundColor: metricType === m.type ? t.copper : t.white, minHeight: 36 }}>
                      <Text style={{ color: metricType === m.type ? t.white : t.copper, fontWeight: '600' }}>{m.label} ({m.unit})</Text>
                    </TouchableOpacity>
                  ))}
                </View>
                <TextInput value={metricValue} onChangeText={setMetricValue} keyboardType="decimal-pad" accessibilityLabel="Valor da medida"
                  placeholder={metricType ? `Valor em ${evolutionData.enabled_metrics?.find((m) => m.type === metricType)?.unit}` : 'Escolha a medida acima'}
                  style={[styles.chatTextInput, { flex: undefined, borderWidth: 1, borderColor: t.border, borderRadius: 8, marginTop: 4 }]} />
                <TextInput value={metricNotes} onChangeText={setMetricNotes} maxLength={500} accessibilityLabel="Observação (opcional)" placeholder="Observação (opcional)"
                  style={[styles.chatTextInput, { flex: undefined, borderWidth: 1, borderColor: t.border, borderRadius: 8, marginTop: 8 }]} />
                {metricError && <Text accessibilityRole="alert" style={styles.errorAlertText}>{metricError}</Text>}
                {metricFeedback && <Text accessibilityRole="alert" style={styles.mealItem}>{metricFeedback}</Text>}
                <TouchableOpacity accessibilityRole="button" style={[styles.navyButton, { marginTop: 12 }]} disabled={metricSaving} onPress={submitSelfMetric}>
                  <Text style={styles.navyButtonText}>{metricSaving ? 'Salvando…' : 'Registrar'}</Text>
                </TouchableOpacity>
              </View>
            )}

            <Text style={[styles.cardTitle, { marginTop: 8 }]} accessibilityRole="header">Registros do período</Text>
            {evolutionData?.clinical_records && evolutionData.clinical_records.length > 0 ? (
              evolutionData.clinical_records.map((c) => (
                <View key={'c' + c.id} style={styles.card}>
                  <Text style={styles.cardTitle}>Avaliação · {displayLocalDateTime(c.date)}</Text>
                  {c.weight_kg != null && <Text style={styles.mealItem}>Peso: {c.weight_kg.toLocaleString('pt-BR')} kg</Text>}
                  {c.waist_cm != null && <Text style={styles.mealItem}>Cintura: {c.waist_cm.toLocaleString('pt-BR')} cm</Text>}
                  {c.hip_cm != null && <Text style={styles.mealItem}>Quadril: {c.hip_cm.toLocaleString('pt-BR')} cm</Text>}
                  {c.bmi != null && <Text style={styles.mealItem}>IMC: {c.bmi.toLocaleString('pt-BR')}</Text>}
                  <Text style={styles.metaLabel}>Registrado pelo seu nutricionista</Text>
                </View>
              ))
            ) : !evolutionState.error && !evolutionState.loading && evolutionData ? (
              <View style={styles.emptyContainer}>
                <Text style={styles.emptyTitle}>Nenhuma avaliação do nutricionista neste período</Text>
              </View>
            ) : null}

            {evolutionData?.self_reports && evolutionData.self_reports.length > 0 && (
              evolutionData.self_reports.map((sr) => (
                <View key={'r' + sr.id} style={styles.card}>
                  <Text style={styles.cardTitle}>Seu registro · {displayLocalDateTime(sr.date)}</Text>
                  <Text style={styles.mealItem}>{sr.label ?? sr.metric_type}: {sr.value.toLocaleString('pt-BR')} {sr.unit}</Text>
                  {sr.notes && <Text style={styles.metaLabel}>{sr.notes}</Text>}
                  <Text style={styles.metaLabel}>Registrado por você</Text>
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
              {logoutError && Platform.OS !== 'web' && (
                <TouchableOpacity accessibilityRole="button" style={{ marginTop: 8 }} onPress={() => { ApiService.forgetLocalSession(); onLogout(); }}>
                  <Text style={styles.mealItem}>Sair só deste aparelho (o acesso no servidor expira sozinho em até 30 dias)</Text>
                </TouchableOpacity>
              )}
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

            <View style={styles.card}>
              <Text style={styles.cardTitle}>🔒 Seus Dados e Privacidade (LGPD)</Text>
              <Text style={styles.helperText}>
                Seus dados cadastrais, clínicos e registros nutricionais são classificados como dados de saúde (Art. 5º, II e Art. 11º da LGPD) sob custódia e sigilo do seu consultório.
              </Text>
              <Text style={[styles.mealItem, { marginTop: 6 }]}>
                • Finalidade: Prestação de assistência e acompanhamento nutricional personalizado.
              </Text>
              <Text style={styles.mealItem}>
                • Prontuário Clínico: Armazenado em infraestrutura protegida com histórico auditável.
              </Text>
              <Text style={styles.mealItem}>
                • Seus direitos: peça abaixo uma cópia dos seus dados ou a exclusão do cadastro. Cada pedido fica registrado com prazo de resposta de 15 dias. Correções podem ser pedidas ao seu nutricionista pelo chat.
              </Text>
              <Text style={[styles.cardTitle, { marginTop: 12 }]} accessibilityRole="header">Consentimentos</Text>
              {privacyError && <Text accessibilityRole="alert" style={styles.errorAlertText}>{privacyError}</Text>}
              {!privacy && !privacyError && <Text style={styles.helperText}>Carregando…</Text>}
              {privacy && privacy.consents.length === 0 && <Text style={styles.helperText}>Nenhum consentimento registrado pelo seu consultório.</Text>}
              {privacy?.consents.map((c, i) => (
                <Text key={`c-${i}`} style={styles.mealItem}>
                  • {c.term_title ?? c.purpose}{c.term_version ? ` (v${c.term_version})` : ''}: {c.granted ? `aceito em ${displayDateTime(c.granted_at)}` : c.revoked_at ? `retirado em ${displayDateTime(c.revoked_at)}` : 'recusado'}
                </Text>
              ))}
              <Text style={styles.helperText}>Para retirar um consentimento, fale com seu nutricionista pelo chat.</Text>

              <Text style={[styles.cardTitle, { marginTop: 12 }]} accessibilityRole="header">Seus pedidos (LGPD)</Text>
              {privacy?.requests.map((r) => (
                <Text key={`r-${r.id}`} style={styles.mealItem}>
                  • {r.request_type === 'export' ? 'Cópia dos dados' : r.request_type === 'deletion' ? 'Exclusão dos dados' : 'Pedido'} de {displayDateTime(r.created_at)}: {r.status === 'pending' || r.status === 'in_review' ? `em andamento, prazo ${displayDateTime(r.deadline_at)}` : 'concluído'}
                </Text>
              ))}
              {privacyFeedback && <Text accessibilityRole="alert" style={styles.mealItem}>{privacyFeedback}</Text>}
              {privacyConfirm === null ? (
                <>
                  <TouchableOpacity accessibilityRole="button" style={styles.navyButton} disabled={privacyBusy} onPress={() => setPrivacyConfirm('export')}><Text style={styles.navyButtonText}>Pedir cópia dos meus dados</Text></TouchableOpacity>
                  <TouchableOpacity accessibilityRole="button" style={[styles.navyButton, { marginTop: 8 }]} disabled={privacyBusy} onPress={() => setPrivacyConfirm('deletion')}><Text style={styles.navyButtonText}>Pedir exclusão dos meus dados</Text></TouchableOpacity>
                </>
              ) : (
                <View accessibilityRole="alert">
                  <Text style={styles.mealItem}>
                    {privacyConfirm === 'export'
                      ? 'Seu consultório vai preparar uma cópia dos seus dados. O prazo de resposta é de 15 dias.'
                      : 'Seu cadastro será anonimizado e o acesso ao app, encerrado. O prontuário clínico é guardado por exigência legal (20 anos). O prazo de resposta é de 15 dias.'}
                  </Text>
                  <TouchableOpacity accessibilityRole="button" style={styles.navyButton} disabled={privacyBusy} onPress={() => submitPrivacyRequest(privacyConfirm)}><Text style={styles.navyButtonText}>{privacyBusy ? 'Enviando…' : 'Confirmar pedido'}</Text></TouchableOpacity>
                  <TouchableOpacity accessibilityRole="button" style={{ marginTop: 8 }} disabled={privacyBusy} onPress={() => setPrivacyConfirm(null)}><Text style={styles.mealItem}>Cancelar</Text></TouchableOpacity>
                </View>
              )}
            </View>
          </View>
        )}
      </ScrollView>

      {/* Barra de Navegação Inferior WCAG AA (NI2-140) */}
      <View style={styles.navBar} accessibilityRole="tablist">
        {(['Hoje', 'Plano', 'Diario', 'Chat', 'Evolucao', 'Perfil'] as NavigationTab[]).map(tab => (
          <TouchableOpacity
            key={tab}
            style={[styles.navItem, currentTab === tab && styles.navItemActive]}
            onPress={() => setCurrentTab(tab)}
            accessibilityRole="tab"
            aria-selected={currentTab === tab}
            accessibilityState={{ selected: currentTab === tab }}
            accessibilityLabel={`Aba ${tabLabels[tab]}`}
          >
            <Text style={[styles.navText, currentTab === tab && styles.navTextActive]}>
              {tabLabels[tab]}
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
  telehealthButton: {
    backgroundColor: '#027A48',
    paddingVertical: 10,
    paddingHorizontal: 16,
    borderRadius: 8,
    minHeight: 44,
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: 10,
  },
  telehealthButtonText: {
    color: '#FFFFFF',
    fontSize: 13,
    fontWeight: '700',
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
  substitutionContainer: {
    marginTop: 4,
    marginLeft: 12,
    backgroundColor: '#FFFBEB',
    padding: 8,
    borderRadius: 6,
    borderLeftWidth: 3,
    borderLeftColor: '#D97706',
  },
  substitutionHeader: {
    fontSize: 11,
    fontWeight: '700',
    color: '#B45309',
    marginBottom: 2,
  },
  substitutionText: {
    fontSize: 12,
    color: '#78350F',
    marginTop: 2,
  },
  itemNotesText: {
    fontSize: 12,
    color: '#0369A1',
    marginLeft: 12,
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
  registerMealButtonDisabled: {
    backgroundColor: '#94A3B8',
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
    paddingHorizontal: 6,
    minWidth: 44,
    minHeight: 44,
  },
  navItemActive: {
    borderBottomWidth: 2,
    borderBottomColor: '#E67A3F',
  },
  navText: {
    fontSize: 11,
    fontWeight: '500',
    color: '#667085',
  },
  navTextActive: {
    color: '#0F2747',
    fontWeight: '700',
  },

  // Chat Styles
  chatHeaderCard: {
    backgroundColor: '#FFFFFF',
    borderRadius: 12,
    padding: 16,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: '#E2E8F0',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.05,
    shadowRadius: 3,
    elevation: 2,
  },
  chatHeaderInfo: {
    flexDirection: 'row',
    alignItems: 'center',
  },
  chatAvatar: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: '#0F2747',
    alignItems: 'center',
    justifyContent: 'center',
  },
  chatAvatarText: {
    color: '#FFFFFF',
    fontWeight: '700',
    fontSize: 18,
  },
  chatNutriName: {
    fontSize: 16,
    fontWeight: '700',
    color: '#0F2747',
  },
  chatOnlineRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: 2,
  },
  chatOnlineDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: '#10B981',
    marginRight: 6,
  },
  chatOnlineText: {
    fontSize: 12,
    fontWeight: '600',
    color: t.success,
  },
  chatSecurityBadge: {
    fontSize: 11,
    color: '#64748B',
    marginTop: 8,
    fontStyle: 'italic',
  },
  chatMessagesCard: {
    backgroundColor: '#FFFFFF',
    borderRadius: 12,
    padding: 16,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: '#E2E8F0',
    minHeight: 280,
  },
  chatEmptyState: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 40,
    paddingHorizontal: 20,
  },
  chatEmptyIcon: {
    fontSize: 36,
    marginBottom: 12,
  },
  chatEmptyTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: '#0F2747',
    marginBottom: 6,
    textAlign: 'center',
  },
  chatEmptyDescription: {
    fontSize: 13,
    color: '#64748B',
    textAlign: 'center',
    lineHeight: 18,
  },
  chatBubble: {
    maxWidth: '82%',
    paddingVertical: 10,
    paddingHorizontal: 14,
    borderRadius: 14,
    marginBottom: 10,
  },
  chatBubblePatient: {
    alignSelf: 'flex-end',
    backgroundColor: t.success,
    borderBottomRightRadius: 4,
  },
  chatBubbleProfessional: {
    alignSelf: 'flex-start',
    backgroundColor: '#F1F5F9',
    borderBottomLeftRadius: 4,
    borderWidth: 1,
    borderColor: '#E2E8F0',
  },
  chatSenderLabel: {
    fontSize: 11,
    fontWeight: '700',
    color: '#0F2747',
    marginBottom: 4,
  },
  chatBubbleText: {
    fontSize: 14,
    lineHeight: 20,
  },
  chatBubbleTextPatient: {
    color: '#FFFFFF',
  },
  chatBubbleTextProfessional: {
    color: '#0F172A',
  },
  chatBubbleFooter: {
    flexDirection: 'row',
    justifyContent: 'flex-end',
    alignItems: 'center',
    marginTop: 4,
    gap: 4,
  },
  chatTimeText: {
    fontSize: 10,
  },
  chatTimePatient: {
    color: 'rgba(255, 255, 255, 0.8)',
  },
  chatTimeProfessional: {
    color: '#64748B',
  },
  chatStatusCheck: {
    fontSize: 10,
    color: '#FFFFFF',
    fontWeight: '700',
  },
  chatInputCard: {
    flexDirection: 'row',
    backgroundColor: '#FFFFFF',
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#CBD5E1',
    padding: 8,
    alignItems: 'flex-end',
    marginBottom: 80,
  },
  chatTextInput: {
    flex: 1,
    fontSize: 14,
    color: '#0F2747',
    paddingHorizontal: 10,
    paddingVertical: 8,
    maxHeight: 100,
    minHeight: 40,
  },
  chatSendBtn: {
    backgroundColor: t.success,
    borderRadius: 8,
    paddingHorizontal: 16,
    paddingVertical: 10,
    justifyContent: 'center',
    alignItems: 'center',
    minHeight: 40,
  },
  chatSendBtnDisabled: {
    backgroundColor: '#94A3B8',
  },
  chatSendBtnText: {
    color: '#FFFFFF',
    fontWeight: '700',
    fontSize: 14,
  },
  chatErrorBanner: {
    backgroundColor: '#FEE2E2',
    borderWidth: 1,
    borderColor: '#F87171',
    borderRadius: 8,
    padding: 10,
    marginBottom: 12,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  chatErrorText: {
    fontSize: 12,
    color: '#991B1B',
    flex: 1,
  },
  chatRetryButton: {
    backgroundColor: '#DC2626',
    borderRadius: 6,
    paddingVertical: 6,
    paddingHorizontal: 12,
    marginLeft: 8,
    minHeight: 36,
    justifyContent: 'center',
  },
  chatRetryButtonText: {
    color: '#FFFFFF',
    fontSize: 12,
    fontWeight: '700',
  },
});
