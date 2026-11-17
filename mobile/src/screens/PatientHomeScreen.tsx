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
  Linking,
  Platform,
  AppState,
} from 'react-native';
import { displayDateTime, displayLocalDateTime, isMealToday, tabLabels } from '../utils/presentation';
import EvolutionChart from '../components/EvolutionChart';
import NextAppointmentCard from '../components/NextAppointmentCard';
import DailyCheckinCard from '../components/DailyCheckinCard';
import PatientChat from '../components/PatientChat';
import PatientNotifications from '../components/PatientNotifications';
import PatientShoppingList from '../components/PatientShoppingList';
import { setupPushNotifications, onPushOpened } from '../services/pushNotifications';
import BrandLogo from '../components/BrandLogo';
import PatientOverview from '../components/PatientOverview';
import Feather from '@expo/vector-icons/Feather';
import HydrationHistory from '../components/HydrationHistory';
import { HydrationQueue, QueuedHydration, memoryStorage, webStorage } from '../services/hydrationQueue';
import { patientTokens as t } from '../theme/patient';
import { announce, useAnnounce, radioKeyboardProps } from '../utils/a11y';
import {
  ApiService,
  ApiError,
  PatientAuthData,
  PatientHomeData,
  FoodDiaryEntry,
  EvolutionData,
  PatientRecipePrescription,
  PatientRecipeDetails,
  PatientPrivacyData,
  HydrationResult,
} from '../services/api';

type ResourceState = { loading: boolean; error: string | null; updatedAt: string | null };
const initialResource: ResourceState = { loading: false, error: null, updatedAt: null };
/** Fuso do aparelho (ex.: America/Sao_Paulo), enviado junto do horário da refeição. */
function deviceTimezone(): string | undefined {
  try { return Intl.DateTimeFormat().resolvedOptions().timeZone || undefined; } catch { return undefined; }
}
function resourceError(error: unknown): string {
  if (error instanceof ApiError) return error.message + (error.requestId ? ` Código de atendimento: ${error.requestId}.` : '');
  return 'Não foi possível carregar os dados. Tente novamente.';
}
function ResourceNotice({ state, retry, name }: { state: ResourceState; retry: () => void; name: string }) {
  return <View style={{ marginBottom: t.space }}>
    {state.loading && <ActivityIndicator accessibilityLabel={`Atualizando ${name}`} />}
    {state.updatedAt && <Text style={{ color: t.muted }} accessibilityLiveRegion="polite">{state.error ? 'Dados desatualizados. ' : ''}Última atualização: {state.updatedAt}</Text>}
    {state.error && <Text style={{ color: t.error }} accessibilityRole="alert" accessibilityLiveRegion="assertive">{state.error}</Text>}
    <TouchableOpacity accessibilityRole="button" accessibilityLabel={`${state.error ? 'Tentar novamente' : 'Atualizar'} ${name}`} onPress={retry} disabled={state.loading} accessibilityState={{ busy: state.loading, disabled: state.loading }} style={{ minHeight: 44, justifyContent: 'center' }}><Text style={{ color: t.navy, textDecorationLine: 'underline', fontWeight: '600' }}>{state.error ? 'Tentar novamente' : 'Atualizar'}</Text></TouchableOpacity>
  </View>;
}

export type NavigationTab = 'Hoje' | 'Plano' | 'Diario' | 'Chat' | 'Evolucao' | 'Perfil';

export default function PatientHomeScreen({ session, onLogout }: { session: PatientAuthData; onLogout: () => void }) {
  const [currentTab, setCurrentTab] = useState<NavigationTab>('Hoje');
  const sectionScroll = useRef<ScrollView>(null);
  const sectionHeading = useRef<any>(null);
  // Cada troca abre no início; rascunhos continuam no estado da tela.
  useEffect(() => {
    const frame = requestAnimationFrame(() => {
      sectionScroll.current?.scrollTo({ y: 0, animated: false });
      if (Platform.OS === 'web') sectionHeading.current?.focus?.({ preventScroll: true });
      announce(`Seção ${tabLabels[currentTab]}`);
    });
    return () => cancelAnimationFrame(frame);
  }, [currentTab]);
  const [loading, setLoading] = useState<boolean>(true);
  const [refreshing, setRefreshing] = useState<boolean>(false);
  const [isOffline, setIsOffline] = useState<boolean>(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Dados reais carregados da API
  const [homeData, setHomeData] = useState<PatientHomeData | null>(null);
  const [diaryEntries, setDiaryEntries] = useState<FoodDiaryEntry[]>([]);
  const [evolutionData, setEvolutionData] = useState<EvolutionData | null>(null);
  const [diaryState, setDiaryState] = useState<ResourceState>(initialResource);
  const [diaryCursor, setDiaryCursor] = useState<string | null>(null);
  const [diaryMoreLoading, setDiaryMoreLoading] = useState(false);
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
  // NI2-140 (A4): o resultado do registro de água aparece no próprio card, em vez do diálogo nativo (que não aparece no web).
  const [waterFeedback, setWaterFeedback] = useState<{ ok: boolean; text: string } | null>(null);

  const [recipes, setRecipes] = useState<PatientRecipePrescription[]>([]);
  const [recipesState, setRecipesState] = useState<ResourceState>(initialResource);
  const [recipeDetails, setRecipeDetails] = useState<PatientRecipeDetails | null>(null);
  const [selectedPrescription, setSelectedPrescription] = useState<number | null>(null);
  const [recipeUnavailable, setRecipeUnavailable] = useState(false);
  const [mealDraft, setMealDraft] = useState<{ name: string; planned: string } | null>(null);
  const [mealMode, setMealMode] = useState<'planned' | 'different'>('planned');
  const [mealDescription, setMealDescription] = useState('');
  const mealDraftCache = useRef<Record<string, { mode: 'planned' | 'different'; description: string }>>({});
  const mealRegisterButtons = useRef<Record<string, any>>({});
  const [mealFeedback, setMealFeedback] = useState<string | null>(null);
  const [mealError, setMealError] = useState<string | null>(null);
  const [recipeDetailState, setRecipeDetailState] = useState<ResourceState>(initialResource);

  // Chat (NI2-138): o componente PatientChat cuida de vínculo, envio sem duplicar, anexos e atualização.
  // O rascunho fica aqui para outras abas poderem abrir o chat com um texto pronto.
  const [chatInputText, setChatInputText] = useState<string>('');
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
      setDiaryCursor(diary.has_more ? diary.next_cursor : null);
      setDiaryState({ loading: false, error: null, updatedAt: new Date().toLocaleString('pt-BR') });
    } catch (error) {
      if (error instanceof ApiError && error.kind === 'auth') return;
      setDiaryState(prev => ({ ...prev, loading: false, error: resourceError(error) }));
    }
  }, []);
  const loadMoreDiary = async () => {
    if (!diaryCursor || diaryMoreLoading) return;
    setDiaryMoreLoading(true);
    try {
      const more = await ApiService.getFoodDiary({ cursor: diaryCursor });
      setDiaryEntries(prev => [...prev, ...more.entries.filter(e => !prev.some(p => p.id === e.id))]);
      setDiaryCursor(more.has_more ? more.next_cursor : null);
    } catch (error) {
      if (error instanceof ApiError && error.kind === 'auth') return;
      setDiaryState(prev => ({ ...prev, error: resourceError(error) }));
    } finally {
      setDiaryMoreLoading(false);
    }
  };
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

  // NI2-95: aviso no celular. Pede permissão depois do login e, ao tocar no aviso, abre a aba certa.
  useEffect(() => {
    void setupPushNotifications();
    // Lembrete de check-in abre em Hoje, onde fica o check-in do dia (NI2-96); os outros avisos, em Perfil.
    return onPushOpened((category) => setCurrentTab(category === 'chat' ? 'Chat' : category === 'checkin' ? 'Hoje' : 'Perfil'));
  }, []);

  const onRefresh = useCallback(() => {
    setRefreshing(true);
    fetchAllData();
  }, [fetchAllData]);

  // Hidratação (NI2-92): cada toque entra numa fila com chave própria; a fila reenvia com a MESMA chave
  // até o servidor confirmar (sem rede, tempo esgotado, 5xx, 429). Nada duplica e nada some em silêncio.
  const [waterPendingMl, setWaterPendingMl] = useState(0);
  const [waterError, setWaterError] = useState<string | null>(null);
  const [waterNotice, setWaterNotice] = useState<string | null>(null);
  const [waterSending, setWaterSending] = useState(false);
  const [showWaterHistory, setShowWaterHistory] = useState(false);
  const [waterHistoryToken, setWaterHistoryToken] = useState(0);
  const lastWaterTap = useRef(0);
  const waterQueueRef = useRef<HydrationQueue<HydrationResult> | null>(null);
  if (!waterQueueRef.current) {
    const storage = (Platform.OS === 'web' && webStorage(session.patient.id)) || memoryStorage();
    waterQueueRef.current = new HydrationQueue<HydrationResult>(storage, async (item: QueuedHydration) => {
      // 1ª tentativa: horário do servidor (relógio do aparelho pode estar adiantado).
      // Reenvios: horário do toque, para o registro cair no dia/horário em que a paciente bebeu.
      const opts = { idempotencyKey: item.key, loggedAt: item.attempts > 0 ? item.loggedAt : undefined };
      try {
        return await ApiService.logHydration(item.amountMl, opts);
      } catch (err) {
        if (err instanceof ApiError && err.code === 'HYDRATION_DATE_IN_FUTURE') return ApiService.logHydration(item.amountMl, { idempotencyKey: item.key });
        throw err;
      }
    }, session.patient.id);
  }

  const applyWaterTotal = useCallback((total: number) => {
    setHomeData(prev => prev ? { ...prev, water_ml_today: total } : prev);
  }, []);

  const flushWater = useCallback(async () => {
    const queue = waterQueueRef.current;
    if (!queue || queue.items().length === 0) { setWaterPendingMl(0); return; }
    setWaterSending(true);
    try {
      const out = await queue.flush();
      const last = out.sent[out.sent.length - 1];
      if (last) {
        applyWaterTotal(last.result.total_water_today);
        // NI2-140 (A4): confirmação no card e para o leitor de tela (soma o que a fila acabou de enviar).
        const sentMl = out.sent.reduce((sum, i) => sum + i.item.amountMl, 0);
        const okText = `${sentMl} ml registrados. Total de hoje: ${Number(last.result.total_water_today).toLocaleString('pt-BR')} ml.`;
        setWaterFeedback({ ok: true, text: okText });
        announce(okText);
      }
      if (out.rejected.length) {
        const r = out.rejected[out.rejected.length - 1];
        const reason = r.error instanceof ApiError ? r.error.message : 'O servidor recusou o registro.';
        const rejectedText = `Não registramos ${r.item.amountMl} ml: ${reason}`;
        setWaterError(rejectedText);
        setWaterFeedback(null);
      }
      const pending = out.remaining.reduce((sum, i) => sum + i.amountMl, 0);
      setWaterPendingMl(pending);
      if (pending > 0) {
        const e = out.lastRetryableError;
        const why = e instanceof ApiError && e.kind === 'auth' ? 'Sua sessão expirou; entre de novo para enviar.'
          : e instanceof ApiError && e.status === 429 ? 'Muitos registros seguidos; tentaremos de novo em 1 minuto.'
          : e instanceof ApiError && (e.kind === 'offline' || e.kind === 'timeout') ? 'Sem conexão; enviaremos automaticamente quando a internet voltar.'
          : 'O servidor não respondeu; tentaremos de novo automaticamente.';
        const pendingText = `${pending} ml aguardando envio. ${why}${queue.persistent ? '' : ' Mantenha o app aberto até enviar.'}`;
        setWaterNotice(pendingText);
        if (!out.sent.length) setWaterFeedback(null);
        setIsOffline(e instanceof ApiError && e.kind === 'offline');
      } else {
        if (out.sent.length) { setWaterNotice(null); setWaterHistoryToken(n => n + 1); }
        setIsOffline(false);
      }
    } finally {
      setWaterSending(false);
    }
  }, [applyWaterTotal]);

  const handleAddWater = (amountMl: number) => {
    // Toque duplo/rápido não vira dois registros: ignora toques a menos de 800 ms do anterior.
    const now = Date.now();
    if (now - lastWaterTap.current < 800) return;
    lastWaterTap.current = now;
    if (!homeData || !waterQueueRef.current) return;
    setWaterError(null);
    waterQueueRef.current.enqueue(amountMl);
    setWaterPendingMl(waterQueueRef.current.pendingMl());
    flushWater();
  };

  // Reenvio automático: ao abrir, a cada 30 s enquanto houver pendência, quando a rede volta (web)
  // e quando o app volta ao primeiro plano (nativo).
  useEffect(() => {
    flushWater();
    const timer = setInterval(() => { if (waterQueueRef.current?.items().length) flushWater(); }, 30000);
    const onReconnect = () => flushWater();
    const w: any = typeof window !== 'undefined' ? window : null;
    w?.addEventListener?.('online', onReconnect);
    const sub = AppState.addEventListener('change', (st) => { if (st === 'active') flushWater(); });
    return () => { clearInterval(timer); w?.removeEventListener?.('online', onReconnect); sub.remove(); };
  }, [flushWater]);

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
        timezone: deviceTimezone(),
        idempotency_key: pending.key,
      });
      delete pendingMealWrites.current[mealName];
      delete mealDraftCache.current[mealName];
      setDiaryEntries(prev => {
        if (prev.some(e => e.id === entry.id)) return prev;
        return [entry, ...prev];
      });
      setMealFeedback(`${mealName} registrado em ${displayLocalDateTime(entry.consumed_at)}. Você pode conferir no Diário.`);
      setMealError(null);
      setMealDraft(null);
    } catch (err: any) {
      if (err instanceof ApiError && err.kind === 'auth') {
        // A sessão expirou: o app volta ao login. O registro continua pendente com a mesma chave.
        setMealError('Sua sessão expirou. Entre novamente e confirme o registro.');
      } else if (err instanceof ApiError && err.status >= 400 && err.status < 500 && err.status !== 408 && err.status !== 429) {
        // O servidor recusou o conteúdo (validação, chave reutilizada): reenviar o mesmo registro nunca vai funcionar.
        delete pendingMealWrites.current[mealName];
      delete mealDraftCache.current[mealName];
        setMealError(`${err.message} Revise e registre de novo.`);
      } else if (err instanceof ApiError && err.status === 429) {
        setMealError('Você fez muitos registros em pouco tempo. Aguarde um minuto e toque em confirmar de novo; seus dados foram preservados.');
      } else {
        setMealError('Não foi possível confirmar o registro. Seus dados foram preservados. Tente salvar novamente para confirmar o mesmo consumo.');
      }
    } finally {
      mealWriteBusy.current = false;
      setRegisteringMeal(null);
    }
  };

  // NI2-140 (A4): no celular, o leitor de tela também ouve o resultado das ações (na web, as regiões vivas já avisam).
  useAnnounce(mealFeedback);
  useAnnounce(mealError);
  useAnnounce(metricFeedback);
  useAnnounce(metricError);
  useAnnounce(privacyFeedback);
  useAnnounce(logoutError);

  // Tela de Carregamento inicial
  if (loading) {
    return (
      <SafeAreaView style={styles.centerContainer}>
        <ActivityIndicator size="large" color={t.navy} accessibilityLabel="Carregando dados do paciente" />
        <Text style={styles.loadingText}>Conectando ao seu consultório nutricional...</Text>
      </SafeAreaView>
    );
  }

  if (!homeData) return <SafeAreaView style={styles.centerContainer}>
    <Text accessibilityRole="alert" style={styles.errorAlertText}>{errorMessage || 'Não foi possível carregar seus dados.'}</Text>
    <TouchableOpacity style={[styles.navyButton, { marginTop: 16, alignSelf: 'stretch' }]} onPress={fetchAllData} accessibilityRole="button"><Text style={styles.navyButtonText}>Tentar novamente</Text></TouchableOpacity>
    <TouchableOpacity onPress={async () => { try { await ApiService.logout(); onLogout(); } catch (_) { setErrorMessage('Falha ao encerrar sessão. Tente novamente.'); } }} accessibilityRole="button" style={{ minHeight: t.touch, justifyContent: 'center', marginTop: 8 }}><Text style={{ color: t.navy, fontWeight: '600' }}>Encerrar sessão</Text></TouchableOpacity>
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
        accessibilityRole="summary"
        accessibilityLiveRegion="polite"
      >
        <Text style={[styles.syncText, isOffline && styles.syncTextOffline]}>
          {isOffline
            ? 'Sem conexão. Os dados podem estar desatualizados.'
            : errorMessage || diaryState.error || evolutionState.error ? 'Atualização parcial. Confira os avisos em cada aba.' : 'Resumo atualizado com seu consultório'}
        </Text>
      </View>

      {homeUpdatedAt && <Text style={styles.updatedText}>{errorMessage ? 'Resumo desatualizado. ' : ''}Última atualização: {homeUpdatedAt}</Text>}
      {errorMessage && (
        <View style={styles.errorAlert}>
          <Text style={styles.errorAlertText}>{errorMessage}</Text>
          <TouchableOpacity
            style={styles.retryButton}
            onPress={fetchAllData}
            accessibilityRole="button"
            accessibilityLabel="Tentar reconectar ao servidor"
          >
            <Text style={styles.retryButtonText}>Tentar novamente</Text>
          </TouchableOpacity>
        </View>
      )}

      <ScrollView
        ref={sectionScroll}
        role="main"
        contentContainerStyle={styles.scroll}
        refreshControl={
          <RefreshControl refreshing={refreshing} onRefresh={onRefresh} colors={[t.navy]} tintColor={t.navy} />
        }
      >
        <Text ref={sectionHeading} accessibilityRole="header" {...(Platform.OS === 'web' ? { tabIndex: -1 } as any : {})} style={styles.sectionTitle}>{tabLabels[currentTab]}</Text>
        {currentTab === 'Hoje' && (
          <>
            <PatientOverview name={patientName} hasPlan={!!activePlan} meals={activePlan?.meals?.length ?? 0} waterMl={waterMl} waterGoal={waterGoal} onPlan={() => setCurrentTab('Plano')} />
            {mealFeedback && <Text accessibilityLiveRegion="polite" style={[styles.helperText, { color: t.success }]}>{mealFeedback}</Text>}
            {/* Refeições de Hoje com dados reais do plano ou Empty State (Critério 2) */}
            <Text style={styles.sectionTitle} accessibilityRole="header">Suas refeições de hoje</Text>

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
                                ou {s.amount ? `${s.amount} ${s.measure_unit || ''} de ` : ''}{s.food_name}{s.notes ? ` • ${s.notes}` : ''}
                              </Text>
                            ))}
                          </View>
                        )}
                        {item.notes ? (
                          <Text style={styles.itemNotesText}>
                            Orientação: {item.notes}
                          </Text>
                        ) : null}
                      </View>
                    ))
                  ) : (
                    <Text style={styles.emptyItemsText}>Nenhum item cadastrado nesta refeição.</Text>
                  )}

                  {diaryEntries.filter(entry => entry.meal_name === meal.name && isMealToday(entry.consumed_at)).slice(0, 1).map(entry => <Text key={entry.id} style={[styles.helperText, { color: t.success }]}>Consumo registrado em {displayLocalDateTime(entry.consumed_at)}</Text>)}
            {mealDraft?.name === meal.name && <View style={styles.card}>
              <Text style={styles.cardTitle}>Registrar {mealDraft.name}</Text>
              <Text style={styles.helperText}>O que você consumiu? O registro só será enviado ao confirmar. Ao cancelar, o texto fica guardado nesta tela até você sair ou atualizar.</Text>
              <View accessibilityRole="radiogroup" accessibilityLabel="Como foi o consumo">
                {(['planned', 'different'] as const).map(mode => <TouchableOpacity {...radioKeyboardProps(() => setMealMode(mode), registeringMeal !== null || !!mealError)} key={mode} accessibilityRole="radio" aria-checked={mealMode === mode} accessibilityState={{ checked: mealMode === mode }} disabled={registeringMeal !== null || !!mealError} onPress={() => setMealMode(mode)} style={[styles.navyButton, { marginTop: 8, backgroundColor: mealMode === mode ? t.navy : t.background }]}>
                  <Text style={{ color: mealMode === mode ? t.white : t.navy }}>{mode === 'planned' ? 'Conforme o plano' : 'Comi diferente'}</Text>
                </TouchableOpacity>)}
              </View>
              {mealMode === 'planned' ? <Text style={styles.mealItem}>{mealDraft.planned}</Text> : <>
                <Text style={styles.helperText}>Conte o que comeu e a quantidade aproximada *</Text>
                <TextInput accessibilityLabel="O que você comeu e quantidade aproximada" multiline maxLength={1500} editable={registeringMeal === null && !mealError} value={mealDescription} onChangeText={setMealDescription} placeholder="Ex.: um sanduíche de queijo e um copo de água" placeholderTextColor={t.muted} style={[styles.chatTextInput, { flex: undefined, borderWidth: 1, borderColor: t.inputBorder, borderRadius: t.inputRadius, minHeight: 88, marginTop: 8 }]} />
              </>}
              {mealError && <Text accessibilityRole="alert" style={{ color: t.error }}>{mealError}</Text>}
              <TouchableOpacity accessibilityRole="button" disabled={registeringMeal !== null || (mealMode === 'different' && !mealDescription.trim())} style={[styles.navyButton, { marginTop: 12 }]} onPress={() => handleRegisterMeal(mealDraft.name, mealMode === 'planned' ? `Conforme o plano: ${mealDraft.planned}` : `Comi diferente: ${mealDescription.trim()}`)}>
                <Text style={styles.navyButtonText}>{registeringMeal ? 'Salvando...' : mealError ? 'Tentar confirmar registro' : 'Confirmar consumo'}</Text>
              </TouchableOpacity>
              <TouchableOpacity accessibilityRole="button" disabled={registeringMeal !== null} onPress={() => {
                mealDraftCache.current[mealDraft.name] = { mode: mealMode, description: mealDescription };
                const name = mealDraft.name;
                setMealDraft(null);
                requestAnimationFrame(() => mealRegisterButtons.current[name]?.focus?.({ preventScroll: true }));
              }} style={{ minHeight: 44, justifyContent: 'center' }}><Text style={{ color: t.navy }}>{mealError ? 'Voltar e confirmar depois' : 'Cancelar'}</Text></TouchableOpacity>
            </View>}
                  <View style={styles.actionRow}>
                    <TouchableOpacity
                      ref={node => { mealRegisterButtons.current[meal.name] = node; }}
                      style={[styles.registerMealButton, registeringMeal === meal.name && styles.registerMealButtonDisabled]}
                      onPress={() => {
                        const pending = pendingMealWrites.current[meal.name];
                        const cached = mealDraftCache.current[meal.name];
                        setMealDraft({ name: meal.name, planned: meal.items.map(item => `${item.quantity} ${item.unit} de ${item.food_name}`).join('; ') });
                        setMealMode(pending ? pending.description.startsWith('Comi diferente:') ? 'different' : 'planned' : cached?.mode ?? 'planned');
                        setMealDescription(pending?.description.replace(/^Comi diferente: /, '') ?? cached?.description ?? '');
                        setMealError(pending ? 'Este consumo aguarda confirmação. Tente salvar novamente sem alterar os dados.' : null);
                      }}
                      disabled={registeringMeal !== null || mealDraft !== null}
                      accessibilityRole="button"
                      accessibilityLabel={(registeringMeal === meal.name ? 'Registrando...' : diaryEntries.some(entry => entry.meal_name === meal.name && isMealToday(entry.consumed_at)) ? 'Registrar outro consumo' : 'Registrar consumo') + ' da refeição ' + meal.name}
                    >
                      <Text style={styles.registerMealText}>
                        {registeringMeal === meal.name ? 'Registrando...' : diaryEntries.some(entry => entry.meal_name === meal.name && isMealToday(entry.consumed_at)) ? 'Registrar outro consumo' : 'Registrar consumo'}
                      </Text>
                    </TouchableOpacity>
                  </View>
                </View>
              ))
            ) : (
              <View style={styles.emptyContainer}>
                <Text style={styles.emptyTitle}>Nenhum plano publicado</Text>
                <Text style={styles.emptyDescription}>
                  Seu nutricionista ainda não publicou um plano alimentar ativo. Assim que a prescrição for disponibilizada, você verá suas refeições aqui.
                </Text>
              </View>
            )}
            <Text style={styles.sectionTitle} accessibilityRole="header">Seu acompanhamento</Text>
            <TouchableOpacity style={styles.card} accessibilityRole="button" onPress={() => setCurrentTab('Plano')}>
              <Text style={styles.cardTitle}>Minhas receitas</Text>
              <Text style={styles.helperText}>Veja as receitas e orientações prescritas pelo seu nutricionista</Text>
            </TouchableOpacity>

            <TouchableOpacity
              style={[styles.card, { borderColor: t.success, backgroundColor: t.successBg }]}
              accessibilityRole="button"
              onPress={() => setCurrentTab('Chat')}
            >
              <Text style={[styles.cardTitle, { color: t.success }]}>Mensagens com o nutricionista</Text>
              <Text style={[styles.helperText, { color: t.success }]}>
                Tire dúvidas sobre o plano. Não é canal de urgência.
              </Text>
            </TouchableOpacity>

            {/* Card da próxima consulta (NI2-137): data com fuso, modalidade, local e ações liberadas pelo consultório */}
            <NextAppointmentCard appointment={nextApp} onChanged={reloadHome} onOpenTelehealth={handleOpenTelehealth} />
            {telehealthError && <Text style={{ color: t.error, marginBottom: t.space }} accessibilityRole="alert" accessibilityLiveRegion="assertive">{telehealthError}</Text>}

            {/* NI2-96: check-in do dia (um por dia, corrigível no mesmo dia) */}
            <DailyCheckinCard checkin={homeData?.checkin_today ?? null} />

            {/* Card de Hidratação (PX-20 / NI2-313; fila, histórico e correção NI2-92; rótulos e barra acessíveis NI2-140) */}
            <View style={styles.card} testID="water-card">
              <Text style={styles.cardTitle} accessibilityRole="header">Hidratação</Text>
              {waterGoal !== null && waterGoal > 0 ? (
                <>
                  <Text style={styles.waterValue} accessibilityLabel={`Água hoje: ${waterMl} ml de ${waterGoal} ml da meta`}>{waterMl.toLocaleString('pt-BR')} ml / {waterGoal.toLocaleString('pt-BR')} ml</Text>
                  <View
                    style={styles.progressBarBackground}
                    accessibilityRole="progressbar"
                    accessibilityLabel="Progresso da meta de água"
                    accessibilityValue={{ min: 0, max: waterGoal, now: Math.min(waterMl, waterGoal) }}
                  >
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
                  <Text style={styles.waterValue} accessibilityLabel={`Água hoje: ${waterMl} ml`}>{waterMl.toLocaleString('pt-BR')} ml</Text>
                  <Text style={styles.metaLabel}>Seu nutricionista ainda não definiu uma meta de água.</Text>
                </View>
              )}

              <View style={styles.buttonRow}>
                <TouchableOpacity
                  style={styles.navyButton}
                  onPress={() => handleAddWater(250)}
                  accessibilityRole="button"
                  accessibilityLabel="Adicionar 250 ml de água"
                  testID="water-add-250"
                >
                  <Text style={styles.navyButtonText}>+ 250 ml</Text>
                </TouchableOpacity>
                <TouchableOpacity
                  style={[styles.navyButton, { backgroundColor: t.accent }]}
                  onPress={() => handleAddWater(500)}
                  accessibilityRole="button"
                  accessibilityLabel="Adicionar 500 ml de água"
                  testID="water-add-500"
                >
                  <Text style={[styles.navyButtonText, { color: t.accentInk }]}>+ 500 ml</Text>
                </TouchableOpacity>
              </View>
              {waterFeedback && (
                <Text
                  testID="water-feedback"
                  accessibilityRole={waterFeedback.ok ? undefined : 'alert'}
                  accessibilityLiveRegion={waterFeedback.ok ? 'polite' : 'assertive'}
                  style={[styles.inlineFeedback, waterFeedback.ok ? styles.inlineFeedbackOk : styles.inlineFeedbackError]}
                >
                  {waterFeedback.text}
                </Text>
              )}
              {waterSending && <Text style={styles.metaLabel} accessibilityLiveRegion="polite">Enviando…</Text>}
              {waterPendingMl > 0 && waterNotice && (
                <View style={{ marginTop: 8 }}>
                  <Text testID="water-pending" accessibilityLiveRegion="polite" style={{ color: t.copper }}>{waterNotice}</Text>
                  <TouchableOpacity style={{ minHeight: 44, justifyContent: 'center' }} accessibilityRole="button" onPress={flushWater} disabled={waterSending} accessibilityState={{ disabled: waterSending, busy: waterSending }}>
                    <Text style={{ color: t.navy, textDecorationLine: 'underline' }}>Enviar agora</Text>
                  </TouchableOpacity>
                </View>
              )}
              {waterError && <Text testID="water-error" accessibilityRole="alert" accessibilityLiveRegion="assertive" style={{ color: t.error, marginTop: 8 }}>{waterError}</Text>}
              <TouchableOpacity style={{ minHeight: 44, justifyContent: 'center', marginTop: 4 }} accessibilityRole="button" accessibilityState={{ expanded: showWaterHistory }} onPress={() => setShowWaterHistory(v => !v)} testID="water-history-toggle">
                <Text style={{ color: t.navy, textDecorationLine: 'underline' }}>{showWaterHistory ? 'Ocultar histórico' : 'Ver histórico e corrigir'}</Text>
              </TouchableOpacity>
              {showWaterHistory && <HydrationHistory refreshToken={waterHistoryToken} onChanged={(r) => applyWaterTotal(r.total_water_today)} />}
            </View>

          </>
        )}

        {currentTab === 'Plano' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Plano alimentar completo</Text>
            {activePlan ? (
              <>
                <Text style={styles.helperText}>
                  {activePlan.title || 'Plano vigente'} • Disponibilizado pelo nutricionista
                  {activePlan.version ? ` • Versão ${activePlan.version}` : ''}
                  {activePlan.published_at ? ` • Publicado em ${displayDateTime(activePlan.published_at)}` : ''}
                </Text>
                {activePlan.notes && (
                  <View style={styles.card}>
                    <Text style={styles.cardTitle}>Orientações gerais</Text>
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
                                ou {s.amount ? `${s.amount} ${s.measure_unit || ''} de ` : ''}{s.food_name}{s.notes ? ` • ${s.notes}` : ''}
                              </Text>
                            ))}
                          </View>
                        )}
                        {it.notes ? (
                          <Text style={styles.itemNotesText}>
                            Orientação: {it.notes}
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
              {!(selectedPrescription === recipe.id && recipeUnavailable) && <TouchableOpacity accessibilityRole="button" disabled={recipeDetailState.loading} style={[styles.navyButton, recipeDetailState.loading && { opacity: 0.6 }]} onPress={() => openRecipe(recipe)}>
                <Text style={styles.navyButtonText}>Ver ingredientes e preparo</Text>
              </TouchableOpacity>}
              {selectedPrescription === recipe.id && <>
                {recipeDetailState.loading && <ActivityIndicator accessibilityLabel="Carregando receita" />}
                {recipeDetailState.error && <>
                  <Text accessibilityRole="alert" style={{ color: t.error }}>{recipeUnavailable ? 'A versão desta prescrição não tem conteúdo completo disponível. Peça ao nutricionista que revise a prescrição no chat.' : recipeDetailState.error + ' Tente abrir novamente.'}</Text>
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

            <PatientShoppingList patientId={session.patient.id} active={currentTab === 'Plano'} />

          </View>
        )}

        {currentTab === 'Diario' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Diário alimentar</Text>
            <Text style={styles.helperText}>Refeições que você registrou e os comentários do seu nutricionista, com data e hora. O que veio do plano aparece como “Conforme o plano”.</Text>

            {<ResourceNotice state={diaryState} retry={refreshDiary} name="diário" />}
            {diaryEntries.length > 0 ? (
              diaryEntries.map((entry) => (
                <View key={entry.id} style={styles.card}>
                  <Text style={styles.cardTitle}>
                    {entry.meal_name} • {displayLocalDateTime(entry.consumed_at)}
                  </Text>
                  <Text style={styles.mealItem}>{!entry.description || ['Consumo registrado via aplicativo móvel', 'Consumo informado pelo paciente'].includes(entry.description.trim().replace(/\.$/, '')) ? 'Este registro antigo não informa alimentos ou quantidades.' : entry.description}</Text>
                  {entry.feedback_from_professional && (
                    <View style={styles.feedbackBox}>
                      <Text style={styles.feedbackTitle}>Retorno do nutricionista:</Text>
                      <Text style={styles.feedbackText}>{entry.feedback_from_professional}</Text>
                    </View>
                  )}
                  <Text style={styles.diaryStatus}>Registrado por você</Text>
                </View>
              ))
            ) : !diaryState.error && !diaryState.loading ? (
              <View style={styles.emptyContainer}>
                <Text style={styles.emptyTitle}>Nenhum registro ainda</Text>
                <Text style={styles.emptyDescription}>
                  Toque em &ldquo;Registrar consumo&rdquo; na aba Hoje para anotar o que você comeu.
                </Text>
              </View>
            ) : null}
            {diaryCursor && (
              <TouchableOpacity accessibilityRole="button" accessibilityLabel="Carregar registros mais antigos do diário" onPress={loadMoreDiary} disabled={diaryMoreLoading} accessibilityState={{ busy: diaryMoreLoading, disabled: diaryMoreLoading }} style={{ minHeight: 44, justifyContent: 'center' }}>
                <Text style={{ color: t.navy }}>{diaryMoreLoading ? 'Carregando...' : 'Carregar registros mais antigos'}</Text>
              </TouchableOpacity>
            )}
          </View>
        )}

        {currentTab === 'Chat' && (
          <View style={styles.tabContent}>
            <PatientChat active={currentTab === 'Chat'} draft={chatInputText} setDraft={setChatInputText} />
          </View>
        )}

        {currentTab === 'Evolucao' && (
          <View style={styles.tabContent}>
            <Text style={styles.sectionTitle} accessibilityRole="header">Evolução e metas</Text>

            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 12 }} accessibilityRole="radiogroup" accessibilityLabel="Período">
              {([['30', '30 dias'], ['90', '3 meses'], ['365', '12 meses'], ['all', 'Tudo']] as const).map(([key, label]) => (
                <TouchableOpacity key={key} onPress={() => setEvolutionPeriod(key)} accessibilityRole="radio" accessibilityState={{ checked: evolutionPeriod === key }} aria-checked={evolutionPeriod === key}
                  style={{ paddingVertical: 8, paddingHorizontal: 12, borderRadius: 16, borderWidth: 1, borderColor: t.navy, backgroundColor: evolutionPeriod === key ? t.navy : t.white, minHeight: t.touch, justifyContent: 'center' }}>
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
                      style={{ paddingVertical: 8, paddingHorizontal: 12, borderRadius: 16, borderWidth: 1, borderColor: t.copper, backgroundColor: metricType === m.type ? t.copper : t.white, minHeight: t.touch, justifyContent: 'center' }}>
                      <Text style={{ color: metricType === m.type ? t.white : t.copper, fontWeight: '600' }}>{m.label} ({m.unit})</Text>
                    </TouchableOpacity>
                  ))}
                </View>
                <TextInput value={metricValue} onChangeText={setMetricValue} keyboardType="decimal-pad" accessibilityLabel="Valor da medida"
                  placeholder={metricType ? `Valor em ${evolutionData.enabled_metrics?.find((m) => m.type === metricType)?.unit}` : 'Escolha a medida acima'} placeholderTextColor={t.muted}
                  style={[styles.chatTextInput, { flex: undefined, borderWidth: 1, borderColor: t.inputBorder, borderRadius: t.inputRadius, marginTop: 4 }]} />
                <TextInput value={metricNotes} onChangeText={setMetricNotes} maxLength={500} accessibilityLabel="Observação (opcional)" placeholder="Observação (opcional)" placeholderTextColor={t.muted}
                  style={[styles.chatTextInput, { flex: undefined, borderWidth: 1, borderColor: t.inputBorder, borderRadius: t.inputRadius, marginTop: 8 }]} />
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
                <Text style={styles.emptyDescription}>
                  {evolutionPeriod !== 'all' ? 'Escolha “Tudo” acima para ver registros mais antigos. ' : ''}
                  As medidas da consulta aparecem aqui depois que seu nutricionista registrar a avaliação.
                  {(evolutionData.enabled_metrics ?? []).length > 0 ? ' Você também pode registrar uma medida sua no formulário acima.' : ''}
                </Text>
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
            <Text style={styles.sectionTitle} accessibilityRole="header">Perfil e privacidade</Text>
            <View style={styles.card}>
              <Text style={styles.cardTitle}>{patientName}</Text>
              <Text style={styles.mealItem}>E-mail: {session.patient.email}</Text>
              {logoutError && <Text accessibilityRole="alert" style={styles.errorAlertText}>{logoutError}</Text>}
              {logoutError && Platform.OS !== 'web' && (
                <TouchableOpacity accessibilityRole="button" style={{ marginTop: 8, minHeight: t.touch, justifyContent: 'center' }} onPress={() => { ApiService.forgetLocalSession(); onLogout(); }}>
                  <Text style={styles.mealItem}>Sair só deste aparelho (o acesso no servidor expira sozinho em até 30 dias)</Text>
                </TouchableOpacity>
              )}
              <TouchableOpacity
                style={[styles.secondaryAction, { marginTop: 16 }, loggingOut && { opacity: 0.6 }]}
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
              >
                <Text style={{ color: t.navy, fontWeight: '600' }}>{loggingOut ? 'Encerrando sessão...' : 'Encerrar sessão'}</Text>
              </TouchableOpacity>
            </View>

            <PatientNotifications active={currentTab === 'Perfil'} />

            <View style={styles.card}>
              <Text style={styles.cardTitle}>Seus dados e privacidade (LGPD)</Text>
              <Text style={styles.helperText}>
                Seus dados cadastrais, clínicos e registros nutricionais são classificados como dados de saúde (Art. 5º, II e Art. 11º da LGPD) sob custódia e sigilo do seu consultório.
              </Text>
              <Text style={[styles.mealItem, { marginTop: 6 }]}>
                • Finalidade: prestação de assistência e acompanhamento nutricional personalizado.
              </Text>
              <Text style={styles.mealItem}>
                • Prontuário clínico: armazenado em infraestrutura protegida com histórico auditável.
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
                  <TouchableOpacity accessibilityRole="button" style={{ marginTop: 8, minHeight: t.touch, justifyContent: 'center' }} disabled={privacyBusy} onPress={() => setPrivacyConfirm(null)}><Text style={styles.mealItem}>Cancelar</Text></TouchableOpacity>
                </View>
              )}
            </View>
          </View>
        )}
      </ScrollView>

      {/* Barra de Navegação Inferior WCAG AA (NI2-140) */}
      {/* NI2-140 (A3): a barra fica no fluxo da tela (não cobre o conteúdo) e rola na horizontal se a fonte estiver grande. */}
      <View style={styles.navBar}>
        <ScrollView horizontal accessibilityRole="tablist" accessibilityLabel="Seções do app" showsHorizontalScrollIndicator={false} contentContainerStyle={styles.navBarContent}>
        {(['Hoje', 'Plano', 'Diario', 'Chat', 'Evolucao', 'Perfil'] as NavigationTab[]).map(tab => (
          <TouchableOpacity
            key={tab}
            style={[styles.navItem, currentTab === tab && styles.navItemActive]}
            onPress={() => setCurrentTab(tab)}
            accessibilityRole="tab"
            accessibilityLabel={tabLabels[tab]}
            aria-selected={currentTab === tab}
            accessibilityState={{ selected: currentTab === tab }}
          >
            <Feather name={({ Hoje: 'sun', Plano: 'book-open', Diario: 'edit-3', Chat: 'message-circle', Evolucao: 'trending-up', Perfil: 'user' } as const)[tab]} size={19} color={currentTab === tab ? t.success : t.muted} accessible={false} importantForAccessibility="no-hide-descendants" />
            <Text style={[styles.navText, currentTab === tab && styles.navTextActive]} maxFontSizeMultiplier={t.maxNavFontScale} numberOfLines={1}>
              {tabLabels[tab]}
            </Text>
          </TouchableOpacity>
        ))}
        </ScrollView>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: t.background, // Sand Premium
  },
  centerContainer: {
    flex: 1,
    backgroundColor: t.background,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 24,
  },
  loadingText: {
    marginTop: 14,
    fontSize: 15,
    color: t.navy,
    fontWeight: '600',
  },
  syncBanner: {
    backgroundColor: t.mintSoft,
    paddingVertical: 8,
    paddingHorizontal: 16,
    alignItems: 'center',
    borderBottomWidth: 1,
    borderBottomColor: t.border,
  },
  syncBannerOffline: {
    backgroundColor: t.errorBg,
    borderBottomColor: '#FECDCA',
  },
  syncText: {
    fontSize: 12,
    fontWeight: '600',
    color: t.navy,
  },
  syncTextOffline: {
    color: t.error,
  },
  errorAlert: {
    backgroundColor: t.errorBg,
    padding: 12,
    marginHorizontal: 16,
    marginTop: 10,
    borderRadius: t.inputRadius,
    borderWidth: 1,
    borderColor: '#FDA29B',
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  errorAlertText: {
    fontSize: 13,
    color: t.error,
    flex: 1,
    fontWeight: '500',
  },
  secondaryAction: { borderWidth: 1, borderColor: t.inputBorder, borderRadius: t.buttonRadius, minHeight: t.touch, paddingHorizontal: 16, justifyContent: 'center', alignItems: 'center' },
  retryButton: {
    backgroundColor: t.error,
    paddingVertical: 6,
    paddingHorizontal: 10,
    borderRadius: 6,
    marginLeft: 8,
    minHeight: 44,
    justifyContent: 'center',
  },
  retryButtonText: {
    color: t.white,
    fontSize: 12,
    fontWeight: '700',
  },
  updatedText: {
    fontSize: 12,
    color: t.muted,
    paddingHorizontal: 16,
    paddingTop: 6,
  },
  inlineFeedback: {
    marginTop: 10,
    padding: 10,
    borderRadius: t.inputRadius,
    fontSize: 14,
    borderWidth: 1,
  },
  inlineFeedbackOk: {
    color: t.success,
    backgroundColor: t.successBg,
    borderColor: t.success,
  },
  inlineFeedbackError: {
    color: t.error,
    backgroundColor: t.errorBg,
    borderColor: t.error,
  },
  scroll: {
    padding: 20,
    paddingBottom: 36,
    width: '100%',
    maxWidth: 760,
    alignSelf: 'center',
  },
  header: {
    marginBottom: 20,
  },
  greeting: {
    fontSize: 26,
    fontWeight: '700',
    color: t.navy, // Navy Premium
  },
  subGreeting: {
    fontSize: 14,
    color: t.muted,
    marginTop: 4,
  },
  card: {
    backgroundColor: t.white,
    borderRadius: t.cardRadius,
    padding: 16,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: t.divider,
  },
  cardTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: t.navy,
    marginBottom: 8,
  },
  appointmentDate: {
    fontSize: 15,
    fontWeight: '600',
    color: t.navy,
  },
  appointmentDoctor: {
    fontSize: 13,
    color: t.muted,
    marginBottom: 12,
  },
  telehealthLink: {
    fontSize: 13,
    color: t.success,
    fontWeight: '600',
    marginBottom: 10,
  },
  telehealthButton: {
    backgroundColor: t.success,
    paddingVertical: 10,
    paddingHorizontal: 16,
    borderRadius: t.inputRadius,
    minHeight: 44,
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: 10,
  },
  telehealthButtonText: {
    color: t.white,
    fontSize: 13,
    fontWeight: '700',
  },
  waterValue: {
    fontSize: 22,
    fontWeight: '700',
    color: t.navy,
    marginBottom: 8,
  },
  progressBarBackground: {
    height: 8,
    backgroundColor: t.divider,
    borderRadius: 4,
    overflow: 'hidden',
    marginBottom: 12,
  },
  progressBarFill: {
    height: '100%',
    backgroundColor: t.navy,
  },
  buttonRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 10,
  },
  navyButton: {
    backgroundColor: t.navy,
    paddingVertical: 10,
    paddingHorizontal: 16,
    borderRadius: t.buttonRadius,
    minHeight: t.touch,
    justifyContent: 'center',
  },
  navyButtonText: {
    color: t.white,
    fontWeight: '600',
    fontSize: 14,
  },
  copperButton: {
    backgroundColor: t.copper, // copper-600: texto branco 5,0:1 (o #E67A3F dava 2,9:1)
    paddingVertical: 10,
    paddingHorizontal: 16,
    borderRadius: t.buttonRadius,
    alignItems: 'center',
    minHeight: t.touch,
    justifyContent: 'center',
  },
  copperButtonText: {
    color: t.white,
    fontWeight: '700',
    fontSize: 14,
  },
  sectionTitle: {
    fontSize: 21,
    fontWeight: '700',
    color: t.navy,
    marginBottom: 12,
    marginTop: 8,
  },
  mealCard: {
    backgroundColor: t.white,
    borderRadius: t.cardRadius,
    padding: 16,
    marginBottom: 14,
    borderWidth: 1,
    borderColor: t.divider,
  },
  mealHeader: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    justifyContent: 'space-between',
    marginBottom: 10,
  },
  mealName: {
    fontSize: 17,
    fontWeight: '700',
    color: t.navy,
  },
  mealTime: {
    backgroundColor: t.mintSoft,
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 14,
    fontSize: 13,
    fontWeight: '600',
    color: t.copper,
  },
  mealItemRow: {
    marginBottom: 6,
  },
  mealItem: {
    fontSize: 14,
    color: t.ink,
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
    color: t.info,
    marginLeft: 12,
    marginTop: 2,
    fontStyle: 'italic',
  },
  emptyItemsText: {
    fontSize: 13,
    color: t.muted,
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
    backgroundColor: t.copper,
    paddingVertical: 8,
    paddingHorizontal: 18,
    borderRadius: t.buttonRadius,
    minHeight: t.touch,
    justifyContent: 'center',
  },
  registerMealButtonDisabled: {
    backgroundColor: t.muted,
  },
  registerMealText: {
    color: t.white,
    fontSize: 13,
    fontWeight: '600',
  },
  tabContent: {
    paddingVertical: 10,
  },
  helperText: {
    fontSize: 13,
    color: t.muted,
    marginBottom: 12,
  },
  emptyContainer: {
    backgroundColor: t.white,
    borderRadius: t.cardRadius,
    padding: 24,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: t.divider,
    marginBottom: 16,
  },
  emptyTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: t.navy,
    marginBottom: 6,
    textAlign: 'center',
  },
  emptyDescription: {
    fontSize: 13,
    color: t.muted,
    textAlign: 'center',
    lineHeight: 18,
  },
  emptyCard: {
    paddingVertical: 8,
  },
  emptyCardText: {
    fontSize: 13,
    color: t.muted,
    fontStyle: 'italic',
  },
  diaryStatus: {
    fontSize: 12,
    fontWeight: '600',
    color: t.success,
    marginTop: 6,
  },
  feedbackBox: {
    backgroundColor: t.mintSoft,
    padding: 10,
    borderRadius: 6,
    marginTop: 8,
    borderLeftWidth: 3,
    borderLeftColor: t.success,
  },
  feedbackTitle: {
    fontSize: 12,
    fontWeight: '700',
    color: t.info,
    marginBottom: 2,
  },
  feedbackText: {
    fontSize: 13,
    color: t.navy,
  },
  metaLabel: {
    fontSize: 12,
    color: t.muted,
    marginTop: 4,
    fontStyle: 'italic',
  },
  // NI2-140 (A3): no fluxo, sem altura fixa; com fonte grande as abas rolam na horizontal.
  navBar: {
    backgroundColor: t.white,
    borderTopWidth: 1,
    borderTopColor: t.divider,
  },
  navBarContent: {
    flexGrow: 1,
    flexDirection: 'row',
    justifyContent: 'space-around',
    alignItems: 'stretch',
    paddingHorizontal: 6,
    paddingVertical: 6,
  },
  navItem: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 9,
    paddingHorizontal: 7,
    gap: 4,
    minWidth: 56,
    minHeight: 48,
    borderBottomWidth: 3,
    borderBottomColor: 'transparent',
  },
  navItemActive: {
    borderBottomColor: t.success,
    backgroundColor: t.mintSoft,
    borderRadius: 18,
  },
  navText: {
    fontSize: 11,
    fontWeight: '500',
    color: t.muted,
  },
  navTextActive: {
    color: t.navy,
    fontWeight: '700',
  },

  // Chat Styles
  chatHeaderCard: {
    backgroundColor: t.white,
    borderRadius: t.cardRadius,
    padding: 16,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: t.divider,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.05,
    shadowRadius: 3,
    elevation: 2,
  },
  chatTextInput: {
    flex: 1,
    fontSize: 14,
    color: t.navy,
    paddingHorizontal: 10,
    paddingVertical: 8,
    maxHeight: 100,
    minHeight: 40,
  },
});
