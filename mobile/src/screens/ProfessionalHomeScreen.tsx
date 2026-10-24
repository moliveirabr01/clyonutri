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
} from 'react-native';
import BrandLogo from '../components/BrandLogo';
import { tokens as t } from '../theme/tokens';
import { displayLocalDateTime } from '../utils/presentation';
import {
  ApiService,
  newChatKey,
  ApiError,
  ProfessionalAuthData,
  ProfessionalDashboardData,
  ProfessionalPatientItem,
  ProfessionalFoodDiaryItem,
  ProfessionalChatConversation,
  ChatMessage,
} from '../services/api';

export type ProfessionalTab = 'Agenda' | 'Pacientes' | 'Diarios' | 'Chat' | 'Perfil';

export default function ProfessionalHomeScreen({
  session,
  onLogout,
}: {
  session: ProfessionalAuthData;
  onLogout: () => void;
}) {
  const [invitation, setInvitation] = useState<{ patientId: number; invitation_code: string; expires_at: string } | null>(null);
  const [inviting, setInviting] = useState<number | null>(null);
  const [currentTab, setCurrentTab] = useState<ProfessionalTab>('Agenda');
  const [loading, setLoading] = useState<boolean>(true);
  const [refreshing, setRefreshing] = useState<boolean>(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Dados reais carregados da API
  const [dashboard, setDashboard] = useState<ProfessionalDashboardData | null>(null);
  const [patients, setPatients] = useState<ProfessionalPatientItem[]>([]);
  const [diaries, setDiaries] = useState<ProfessionalFoodDiaryItem[]>([]);
  const [diaryCursor, setDiaryCursor] = useState<string | null>(null);
  const [diaryMoreLoading, setDiaryMoreLoading] = useState(false);

  // Estado para feedback em diário
  const [selectedEntryId, setSelectedEntryId] = useState<number | null>(null);
  const [feedbackText, setFeedbackText] = useState<string>('');
  const [sendingFeedback, setSendingFeedback] = useState<boolean>(false);
  const [feedbackSuccess, setFeedbackSuccess] = useState<string | null>(null);

  // Estado para Chat com Pacientes
  const [conversations, setConversations] = useState<ProfessionalChatConversation[]>([]);
  const [activeConvId, setActiveConvId] = useState<number | null>(null);
  const [activeConvPatientName, setActiveConvPatientName] = useState<string>('');
  const [activeConvMessages, setActiveConvMessages] = useState<ChatMessage[]>([]);
  const [profChatText, setProfChatText] = useState<string>('');
  const [profChatSending, setProfChatSending] = useState<boolean>(false);
  // NI2-138: erro dentro da tela, conversa só para leitura e chave estável por mensagem (reenvio não duplica).
  const [profChatError, setProfChatError] = useState<string | null>(null);
  const [profChatAccess, setProfChatAccess] = useState<{ can_send: boolean; reason: string | null } | null>(null);
  const profKeyRef = useRef<string | null>(null);
  const lastMsgIdRef = useRef(0);
  useEffect(() => { lastMsgIdRef.current = activeConvMessages.length ? activeConvMessages[activeConvMessages.length - 1].id : 0; }, [activeConvMessages]);

  const [loggingOut, setLoggingOut] = useState<boolean>(false);
  const [logoutError, setLogoutError] = useState<string | null>(null);

  // Estados de erro independente de agenda e busca de pacientes (MOB-UX-007, MOB-UX-008)
  const [dashboardError, setDashboardError] = useState<string | null>(null);
  const [patientSearch, setPatientSearch] = useState<string>('');
  const [patientPage, setPatientPage] = useState<number>(1);
  const [patientTotal, setPatientTotal] = useState<number>(0);
  const [patientHasMore, setPatientHasMore] = useState<boolean>(false);
  const [patientLoading, setPatientLoading] = useState<boolean>(false);

  const fetchDashboard = useCallback(async () => {
    setDashboardError(null);
    try {
      const data = await ApiService.getProfessionalDashboard();
      setDashboard(data);
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'auth') return;
      const msg = err instanceof Error ? err.message : 'Não foi possível carregar a agenda profissional.';
      setDashboardError(msg);
      setErrorMessage(msg);
    }
  }, []);

  const fetchPatients = useCallback(async (query = '', page = 1) => {
    setPatientLoading(true);
    try {
      const res = await ApiService.getProfessionalPatients(query, page, 50);
      setPatients(res.patients || []);
      setPatientTotal(res.total ?? (res.patients || []).length);
      setPatientPage(res.page ?? page);
      setPatientHasMore(Boolean(res.has_more));
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'auth') return;
      setErrorMessage('Não foi possível carregar a lista de pacientes.');
    } finally {
      setPatientLoading(false);
    }
  }, []);

  const fetchDiaries = useCallback(async () => {
    try {
      const res = await ApiService.getProfessionalFoodDiaries();
      setDiaries(res.diaries);
      setDiaryCursor(res.has_more ? res.next_cursor : null);
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'auth') return;
      setErrorMessage('Não foi possível carregar os diários alimentares.');
    }
  }, []);

  const loadMoreDiaries = async () => {
    if (!diaryCursor || diaryMoreLoading) return;
    setDiaryMoreLoading(true);
    try {
      const res = await ApiService.getProfessionalFoodDiaries({ cursor: diaryCursor });
      setDiaries((prev) => [...prev, ...res.diaries.filter((d) => !prev.some((p) => p.id === d.id))]);
      setDiaryCursor(res.has_more ? res.next_cursor : null);
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'auth') return;
      Alert.alert('Erro', 'Não foi possível carregar mais diários. Tente novamente.');
    } finally {
      setDiaryMoreLoading(false);
    }
  };

  const fetchAllData = useCallback(async () => {
    setErrorMessage(null);
    setLoading(true);
    await Promise.all([fetchDashboard(), fetchPatients(), fetchDiaries()]);
    setLoading(false);
    setRefreshing(false);
  }, [fetchDashboard, fetchPatients, fetchDiaries]);

  useEffect(() => {
    fetchAllData();
  }, [fetchAllData]);

  const fetchConversations = useCallback(async () => {
    try {
      const res = await ApiService.getProfessionalChatConversations();
      setConversations(res.conversations || []);
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'auth') return;
    }
  }, []);

  const fetchActiveMessages = useCallback(async (convId: number, afterId?: number) => {
    try {
      const res = await ApiService.getProfessionalChatMessages(convId, afterId);
      if (res.access) setProfChatAccess({ can_send: res.access.can_send, reason: res.access.reason });
      if (afterId && afterId > 0) {
        if (res.messages && res.messages.length > 0) {
          setActiveConvMessages(prev => {
            const existingIds = new Set(prev.map(m => m.id));
            const newMsgs = res.messages.filter(m => !existingIds.has(m.id));
            return newMsgs.length > 0 ? [...prev, ...newMsgs] : prev;
          });
          ApiService.markProfessionalChatAsRead(convId).catch(() => {});
        }
      } else {
        setActiveConvMessages(res.messages || []);
        if (res.messages && res.messages.length > 0) {
          ApiService.markProfessionalChatAsRead(convId).catch(() => {});
        }
      }
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'auth') return;
    }
  }, []);

  useEffect(() => {
    if (currentTab !== 'Chat') return;
    fetchConversations();
    if (activeConvId) {
      fetchActiveMessages(activeConvId);
      const interval = setInterval(() => {
        const lastId = lastMsgIdRef.current;
        fetchActiveMessages(activeConvId, lastId);
      }, 5000);
      return () => clearInterval(interval);
    } else {
      const interval = setInterval(fetchConversations, 5000);
      return () => clearInterval(interval);
    }
  }, [currentTab, activeConvId, fetchConversations, fetchActiveMessages]);

  const handleSendProfMessage = async () => {
    if (!activeConvId || !profChatText.trim() || profChatSending) return;
    setProfChatSending(true);
    setProfChatError(null);
    profKeyRef.current = profKeyRef.current ?? newChatKey('prof');
    try {
      const res = await ApiService.sendProfessionalChatMessage(activeConvId, profChatText.trim(), profKeyRef.current);
      if (res.success && res.message) {
        setActiveConvMessages(prev => (prev.some(m => m.id === res.message.id) ? prev : [...prev, res.message]));
        setProfChatText('');
        profKeyRef.current = null;
      }
    } catch (err) {
      setProfChatError(err instanceof ApiError ? err.message : 'Sem conexão. A mensagem não foi enviada; tente de novo (não duplica).');
    } finally {
      setProfChatSending(false);
    }
  };

  const onRefresh = useCallback(() => {
    setRefreshing(true);
    fetchAllData();
    if (currentTab === 'Chat') {
      fetchConversations();
      if (activeConvId) fetchActiveMessages(activeConvId);
    }
  }, [fetchAllData, currentTab, activeConvId, fetchConversations, fetchActiveMessages]);

  // Envio real de feedback clínico no diário alimentar do paciente
  const handleSendFeedback = async (entryId: number) => {
    if (!feedbackText.trim()) {
      Alert.alert('Atenção', 'Digite sua orientação clínica antes de enviar.');
      return;
    }
    setSendingFeedback(true);
    setFeedbackSuccess(null);
    try {
      const res = await ApiService.submitFoodDiaryFeedback(entryId, feedbackText.trim());
      // Atualiza localmente o diário com o feedback persistido
      setDiaries((prev) =>
        prev.map((item) =>
          item.id === entryId
            ? {
                ...item,
                feedback_from_professional: res.feedback,
                reviewed_at: res.reviewed_at,
              }
            : item
        )
      );
      setFeedbackText('');
      setSelectedEntryId(null);
      setFeedbackSuccess('Feedback clínico registrado e enviado ao paciente.');
      // Atualiza contagem do dashboard
      if (dashboard) {
        setDashboard({
          ...dashboard,
          pending_diaries_count: Math.max(0, dashboard.pending_diaries_count - 1),
        });
      }
    } catch (err: any) {
      if (err instanceof ApiError && err.kind === 'auth') return; // sessão expirada: o app volta ao login
      Alert.alert('Erro', err?.message || 'Não foi possível registrar o feedback clínico.');
    } finally {
      setSendingFeedback(false);
    }
  };

  if (loading && !refreshing) {
    return (
      <SafeAreaView style={styles.centerContainer}>
        <ActivityIndicator size="large" color={t.navy} accessibilityLabel="Carregando consultório" />
        <Text style={styles.loadingText}>Carregando ambiente do nutricionista...</Text>
      </SafeAreaView>
    );
  }

  const profName = session.professional.name;
  const clinicName = session.professional.clinic_name || 'Clínica ClyoNutri';

  return (
    <SafeAreaView style={styles.container}>
      {/* Header do Nutricionista */}
      <View style={styles.headerBar}>
        <View>
          <BrandLogo light compact />
          <Text style={styles.clinicBadge}>{clinicName}</Text>
          <Text accessibilityRole="header" style={styles.headerTitle}>
            Dr(a). {profName}
          </Text>
        </View>
        <View style={styles.statusIndicator}>
          <Text style={styles.statusText}>● Online</Text>
        </View>
      </View>

      {errorMessage && (
        <View style={styles.errorBanner} accessibilityRole="alert">
          <Text style={styles.errorText}>{errorMessage}</Text>
          <TouchableOpacity
            style={styles.retryBtn}
            onPress={fetchAllData}
            accessibilityRole="button"
            accessibilityLabel="Tentar novamente"
          >
            <Text style={styles.retryBtnText}>Recarregar</Text>
          </TouchableOpacity>
        </View>
      )}

      {feedbackSuccess && (
        <View style={styles.successBanner} accessibilityRole="alert">
          <Text style={styles.successText}>✓ {feedbackSuccess}</Text>
        </View>
      )}

      <ScrollView
        contentContainerStyle={styles.scroll}
        refreshControl={
          <RefreshControl refreshing={refreshing} onRefresh={onRefresh} colors={[t.navy]} tintColor={t.navy} />
        }
      >
        {/* ABA 1: AGENDA DE HOJE */}
        {currentTab === 'Agenda' && (
          <View>
            <Text style={styles.sectionHeading} accessibilityRole="header">
              Consultas de Hoje
            </Text>

            {/* Métricas rápidas da clínica */}
            {dashboard && (
              <View style={styles.metricsRow}>
                <View style={styles.metricCard}>
                  <Text style={styles.metricNumber}>{dashboard.today_stats.total}</Text>
                  <Text style={styles.metricLabel}>Agendadas Hoje</Text>
                </View>
                <View style={styles.metricCard}>
                  <Text style={styles.metricNumber}>{dashboard.pending_diaries_count}</Text>
                  <Text style={styles.metricLabel}>Diários Pendentes</Text>
                </View>
                <View style={styles.metricCard}>
                  <Text style={styles.metricNumber}>{dashboard.active_patients_count}</Text>
                  <Text style={styles.metricLabel}>Pacientes Ativos</Text>
                </View>
              </View>
            )}

            {/* Lista de Atendimentos (MOB-UX-007: distinção entre erro e agenda vazia) */}
            {dashboardError ? (
              <View style={styles.emptyContainer}>
                {/* NI2-140 (A2): só a mensagem é alerta; o botão fica fora do grupo para o leitor de tela alcançá-lo. */}
                <View accessibilityRole="alert" accessibilityLiveRegion="assertive">
                  <Text style={[styles.emptyTitle, { color: t.error }]}>Falha ao carregar atendimentos</Text>
                  <Text style={styles.emptyDescription}>{dashboardError}</Text>
                </View>
                <TouchableOpacity
                  style={[styles.retryBtn, { marginTop: 12, minHeight: 44, justifyContent: 'center' }]}
                  onPress={fetchDashboard}
                  accessibilityRole="button"
                  accessibilityLabel="Tentar carregar a agenda novamente"
                >
                  <Text style={styles.retryBtnText}>Tentar carregar novamente</Text>
                </TouchableOpacity>
              </View>
            ) : dashboard && dashboard.today_appointments && dashboard.today_appointments.length > 0 ? (
              dashboard.today_appointments.map((app) => (
                <View key={app.id} style={styles.card}>
                  <View style={styles.cardHeaderRow}>
                    <Text style={styles.cardTime}>⏰ {app.scheduled_at.substring(11, 16) || app.scheduled_at}</Text>
                    <Text
                      style={[
                        styles.badgeStatus,
                        app.status === 'confirmed' ? styles.badgeConfirmed : styles.badgeScheduled,
                      ]}
                    >
                      {app.status === 'confirmed' ? 'Confirmada' : 'Agendada'}
                    </Text>
                  </View>
                  <Text style={styles.patientName}>{app.patient_name || 'Paciente'}</Text>
                  {app.notes && <Text style={styles.cardNotes}>Notas: {app.notes}</Text>}
                </View>
              ))
            ) : (
              <View style={styles.emptyContainer}>
                <Text style={styles.emptyTitle}>Nenhum atendimento para hoje</Text>
                <Text style={styles.emptyDescription}>
                  Sua agenda está livre no momento. Novos agendamentos aparecerão aqui.
                </Text>
              </View>
            )}
          </View>
        )}

        {/* ABA 2: PACIENTES (MOB-UX-008: busca em tempo real e paginação completa) */}
        {currentTab === 'Pacientes' && (
          <View>
            <Text style={styles.sectionHeading} accessibilityRole="header">
              Pacientes Cadastrados ({patientTotal || patients.length})
            </Text>

            {/* Campo de Busca de Pacientes */}
            <View style={styles.searchContainer}>
              <TextInput
                style={styles.searchInput}
                placeholder="Buscar paciente por nome, e-mail ou telefone..."
                placeholderTextColor="#64748B"
                value={patientSearch}
                onChangeText={(text) => {
                  setPatientSearch(text);
                  fetchPatients(text, 1);
                }}
                accessibilityLabel="Campo de busca na carteira de pacientes"
              />
            </View>

            {patientLoading ? (
              <ActivityIndicator size="small" color={t.navy} style={{ marginVertical: 12 }} />
            ) : null}

            {patients.length > 0 ? (
              <>
                {patients.map((p) => (
                  <View key={p.id} style={styles.card}>
                    <Text style={styles.patientName}>{p.name}</Text>
                    <Text style={styles.patientContact}>📧 {p.email}</Text>
                    {p.phone && <Text style={styles.patientContact}>📱 {p.phone}</Text>}
                    <TouchableOpacity accessibilityRole="button" disabled={inviting !== null} style={{ minHeight: 44, justifyContent: 'center' }} onPress={async () => {
                      if (inviting !== null) return;
                      setInviting(p.id); setErrorMessage(null); setInvitation(null);
                      try { const data = await ApiService.createPatientInvitation(p.id); setInvitation({ ...data, patientId: p.id }); }
                      catch (e) { setErrorMessage(e instanceof Error ? e.message : 'Não foi possível gerar o convite.'); }
                      finally { setInviting(null); }
                    }}><Text style={{ color: t.copper, fontWeight: '600' }}>{inviting === p.id ? 'Gerando convite…' : 'Gerar convite para primeiro acesso'}</Text></TouchableOpacity>
                    {invitation?.patientId === p.id && <View style={{ padding: 12, backgroundColor: t.background, gap: 8 }}>
                      <Text>Entregue este código ao paciente por um canal privado. No app, ele deve escolher Primeiro acesso.</Text>
                      <Text selectable accessibilityLabel="Código do convite">{invitation.invitation_code}</Text>
                      <Text>Validade: 48 horas, uso único. Um novo convite invalida o anterior.</Text>
                      <TouchableOpacity accessibilityRole="button" onPress={() => setInvitation(null)}><Text style={{ color: t.navy, paddingVertical: 10 }}>Ocultar convite</Text></TouchableOpacity>
                    </View>}
                    <View style={styles.patientFooter}>
                      <Text style={styles.patientBadge}>Status: {p.status}</Text>
                      <Text style={styles.patientDate}>Desde: {p.created_at.substring(0, 10)}</Text>
                    </View>
                  </View>
                ))}

                {/* Paginação de Pacientes */}
                <View style={styles.paginationRow}>
                  {patientPage > 1 && (
                    <TouchableOpacity
                      style={styles.pageButton}
                      onPress={() => fetchPatients(patientSearch, patientPage - 1)}
                      accessibilityRole="button"
                      accessibilityLabel="Página anterior de pacientes"
                    >
                      <Text style={styles.pageButtonText}>← Anterior</Text>
                    </TouchableOpacity>
                  )}
                  {patientHasMore && (
                    <TouchableOpacity
                      style={styles.pageButton}
                      onPress={() => fetchPatients(patientSearch, patientPage + 1)}
                      accessibilityRole="button"
                      accessibilityLabel="Próxima página de pacientes"
                    >
                      <Text style={styles.pageButtonText}>Próxima →</Text>
                    </TouchableOpacity>
                  )}
                </View>
              </>
            ) : (
              <View style={styles.emptyContainer}>
                <Text style={styles.emptyTitle}>
                  {patientSearch ? 'Nenhum paciente encontrado' : 'Nenhum paciente cadastrado'}
                </Text>
                <Text style={styles.emptyDescription}>
                  {patientSearch
                    ? `Nenhum resultado corresponde à busca "${patientSearch}".`
                    : 'Cadastre novos pacientes através do portal ou envie convites de onboarding.'}
                </Text>
              </View>
            )}
          </View>
        )}

        {/* ABA 3: DIÁRIOS ALIMENTARES & FEEDBACK CLÍNICO */}
        {currentTab === 'Diarios' && (
          <View>
            <Text style={styles.sectionHeading} accessibilityRole="header">
              Diários Alimentares & Feedback Clínico
            </Text>
            <Text style={styles.helperText}>
              Acompanhe as refeições registradas e envie orientações clínicas aos pacientes.
            </Text>

            {diaries.length > 0 ? (
              diaries.map((d) => (
                <View key={d.id} style={styles.card}>
                  <View style={styles.cardHeaderRow}>
                    <Text style={styles.cardAuthor}>{d.patient_name}</Text>
                    <Text style={styles.cardTimeSmall}>{displayLocalDateTime(d.consumed_at)}</Text>
                  </View>
                  <Text style={styles.mealTitle}>🍽️ {d.meal_name}</Text>
                  {d.description && <Text style={styles.mealDesc}>{d.description}</Text>}

                  {/* Feedback existente */}
                  {d.feedback_from_professional ? (
                    <View style={styles.feedbackGivenBox}>
                      <Text style={styles.feedbackGivenTitle}>✓ Seu feedback clínico:</Text>
                      <Text style={styles.feedbackGivenText}>{d.feedback_from_professional}</Text>
                      {d.reviewed_at && (
                        <Text style={styles.reviewedAtText}>Revisado em: {displayLocalDateTime(d.reviewed_at)}</Text>
                      )}
                    </View>
                  ) : (
                    <View style={styles.pendingFeedbackBox}>
                      <Text style={styles.pendingText}>⏳ Pendente de feedback clínico</Text>
                      {selectedEntryId === d.id ? (
                        <View style={styles.feedbackForm}>
                          <TextInput
                            style={styles.feedbackInput}
                            placeholder="Digite sua orientação clínica para o paciente..."
                            placeholderTextColor={t.muted}
                            value={feedbackText}
                            onChangeText={setFeedbackText}
                            multiline
                            numberOfLines={3}
                            editable={!sendingFeedback}
                            accessibilityLabel={`Orientação para refeição ${d.meal_name}`}
                          />
                          <View style={styles.formBtnRow}>
                            <TouchableOpacity
                              style={styles.cancelBtn}
                              onPress={() => {
                                setSelectedEntryId(null);
                                setFeedbackText('');
                              }}
                              disabled={sendingFeedback}
                              accessibilityRole="button"
                              accessibilityLabel="Cancelar feedback"
                            >
                              <Text style={styles.cancelBtnText}>Cancelar</Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                              style={styles.submitBtn}
                              onPress={() => handleSendFeedback(d.id)}
                              disabled={sendingFeedback}
                              accessibilityRole="button"
                              accessibilityLabel={`Enviar feedback para ${d.patient_name}`}
                            >
                              {sendingFeedback ? (
                                <ActivityIndicator color={t.white} />
                              ) : (
                                <Text style={styles.submitBtnText}>Salvar Feedback</Text>
                              )}
                            </TouchableOpacity>
                          </View>
                        </View>
                      ) : (
                        <TouchableOpacity
                          style={styles.openFeedbackBtn}
                          onPress={() => {
                            setSelectedEntryId(d.id);
                            setFeedbackText('');
                          }}
                          accessibilityRole="button"
                          accessibilityLabel={`Dar feedback para ${d.patient_name}`}
                        >
                          <Text style={styles.openFeedbackText}>Dar Feedback Clínico</Text>
                        </TouchableOpacity>
                      )}
                    </View>
                  )}
                </View>
              ))
            ) : (
              <View style={styles.emptyContainer}>
                <Text style={styles.emptyTitle}>Nenhum registro alimentar</Text>
                <Text style={styles.emptyDescription}>
                  Assim que os pacientes anotarem refeições no aplicativo, elas serão listadas aqui para sua revisão.
                </Text>
              </View>
            )}
            {diaryCursor && (
              <TouchableOpacity
                style={styles.pageButton}
                onPress={loadMoreDiaries}
                disabled={diaryMoreLoading}
                accessibilityRole="button"
                accessibilityLabel="Carregar diários mais antigos"
                accessibilityState={{ busy: diaryMoreLoading, disabled: diaryMoreLoading }}
              >
                <Text style={styles.pageButtonText}>{diaryMoreLoading ? 'Carregando...' : 'Carregar mais antigos'}</Text>
              </TouchableOpacity>
            )}
          </View>
        )}

        {/* ABA 4: CHAT COM PACIENTES */}
        {currentTab === 'Chat' && (
          <View>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
              <Text style={styles.sectionHeading} accessibilityRole="header">
                Chat & Mensagens com Pacientes
              </Text>
              <View style={{ flexDirection: 'row', alignItems: 'center' }}>
                <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: '#10B981', marginRight: 6 }} />
                <Text style={{ fontSize: 12, fontWeight: '700', color: '#059669' }}>Online</Text>
              </View>
            </View>

            {activeConvId ? (
              <View>
                {/* Botão Voltar para Lista */}
                <TouchableOpacity
                  style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 12 }}
                  onPress={() => {
                    setActiveConvId(null);
                    setActiveConvPatientName('');
                    setActiveConvMessages([]);
                  }}
                  accessibilityRole="button"
                  accessibilityLabel="Voltar para lista de conversas"
                >
                  <Text style={{ fontSize: 14, fontWeight: '700', color: t.copper }}>← Voltar para Conversas</Text>
                </TouchableOpacity>

                {/* Header da conversa ativa */}
                <View style={styles.card}>
                  <Text style={styles.patientName}>Paciente: {activeConvPatientName}</Text>
                  <Text style={{ fontSize: 12, color: '#475569' }}>Mensagens com a paciente. Não é canal de urgência.</Text>
                </View>

                {/* Lista de mensagens */}
                <View style={[styles.card, { minHeight: 250 }]}>
                  {activeConvMessages.length === 0 ? (
                    <Text style={{ textAlign: 'center', color: '#64748B', padding: 20 }}>
                      Nenhuma mensagem enviada ainda.
                    </Text>
                  ) : (
                    activeConvMessages.map((m) => {
                      const isProf = m.sender_type === 'professional';
                      return (
                        <View
                          key={m.id}
                          style={{
                            maxWidth: '82%',
                            padding: 10,
                            borderRadius: 12,
                            marginBottom: 8,
                            alignSelf: isProf ? 'flex-end' : 'flex-start',
                            backgroundColor: isProf ? '#059669' : '#F1F5F9',
                          }}
                        >
                          <Text style={{ fontSize: 11, fontWeight: '700', color: isProf ? '#FFFFFF' : '#0F2747', marginBottom: 2 }}>
                            {isProf ? 'Você (Nutricionista)' : activeConvPatientName}
                          </Text>
                          <Text style={{ fontSize: 14, color: isProf ? '#FFFFFF' : '#0F172A' }}>
                            {m.message}
                          </Text>
                          <Text style={{ fontSize: 10, color: isProf ? 'rgba(255,255,255,0.8)' : '#64748B', textAlign: 'right', marginTop: 4 }}>
                            {m.created_at ? m.created_at.substring(11, 16) : ''}
                          </Text>
                        </View>
                      );
                    })
                  )}
                </View>

                {/* Input de envio do profissional (MOB-UX-017, MOB-UX-018: Acessibilidade WCAG AA) */}
                {profChatError ? <Text style={{ color: t.error, marginBottom: 8 }} accessibilityRole="alert">{profChatError}</Text> : null}
                {profChatAccess && !profChatAccess.can_send ? (
                  <Text style={{ color: '#7C2D12', backgroundColor: '#FFF7ED', padding: 12, borderRadius: 10, marginBottom: 80 }}>{profChatAccess.reason}</Text>
                ) : (
                <View style={{ flexDirection: 'row', backgroundColor: '#FFFFFF', borderRadius: 10, padding: 8, borderWidth: 1, borderColor: '#CBD5E1', alignItems: 'flex-end', marginBottom: 80 }}>
                  <TextInput
                    style={{ flex: 1, fontSize: 14, color: '#0F2747', paddingHorizontal: 8, paddingVertical: 6, minHeight: 38, maxHeight: 100 }}
                    placeholder="Digite uma mensagem de orientação..."
                    placeholderTextColor="#64748B"
                    value={profChatText}
                    onChangeText={setProfChatText}
                    multiline
                    editable={!profChatSending}
                    accessibilityLabel="Campo de texto para mensagem de orientação ao paciente"
                  />
                  <TouchableOpacity
                    style={{
                      backgroundColor: (!profChatText.trim() || profChatSending) ? '#64748B' : '#047857',
                      borderRadius: 8,
                      paddingHorizontal: 16,
                      paddingVertical: 10,
                      justifyContent: 'center',
                      alignItems: 'center',
                      minHeight: 44,
                    }}
                    onPress={handleSendProfMessage}
                    disabled={!profChatText.trim() || profChatSending}
                    accessibilityRole="button"
                    accessibilityLabel="Enviar mensagem de orientação"
                  >
                    {profChatSending ? (
                      <ActivityIndicator size="small" color="#FFFFFF" />
                    ) : (
                      <Text style={{ color: '#FFFFFF', fontWeight: '700', fontSize: 13 }}>Enviar</Text>
                    )}
                  </TouchableOpacity>
                </View>
                )}
              </View>
            ) : (
              <View>
                {conversations.length === 0 ? (
                  <View style={styles.emptyContainer}>
                    <Text style={styles.emptyTitle}>Nenhuma conversa ativa</Text>
                    <Text style={styles.emptyDescription}>
                      As conversas com pacientes que têm o chat liberado aparecem aqui.
                    </Text>
                  </View>
                ) : (
                  conversations.map((c) => (
                    <TouchableOpacity
                      key={c.id}
                      style={[styles.card, { borderLeftWidth: 4, borderLeftColor: '#059669' }]}
                      onPress={() => {
                        setActiveConvId(c.id);
                        setActiveConvPatientName(c.patient_name);
                      }}
                      accessibilityRole="button"
                      accessibilityLabel={`Abrir conversa com paciente ${c.patient_name}`}
                    >
                      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                        <Text style={{ fontSize: 15, fontWeight: '700', color: '#0F2747' }}>{c.patient_name}</Text>
                        <Text style={{ fontSize: 11, color: '#64748B' }}>{c.last_message_at ? c.last_message_at.substring(11, 16) : ''}</Text>
                      </View>
                      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                        <Text style={{ fontSize: 13, color: '#64748B', flex: 1 }} numberOfLines={1}>
                          {c.last_message || 'Conversa iniciada...'}
                        </Text>
                        {(c.unread_count ?? 0) > 0 && (
                          <View style={{ backgroundColor: '#059669', borderRadius: 10, paddingHorizontal: 8, paddingVertical: 2, marginLeft: 8 }}>
                            <Text style={{ color: '#FFFFFF', fontSize: 11, fontWeight: '700' }}>{c.unread_count}</Text>
                          </View>
                        )}
                      </View>
                    </TouchableOpacity>
                  ))
                )}
              </View>
            )}
          </View>
        )}

        {/* ABA 5: PERFIL & SEGURANÇA */}
        {currentTab === 'Perfil' && (
          <View>
            <Text style={styles.sectionHeading} accessibilityRole="header">
              Perfil Profissional & Acesso
            </Text>
            <View style={styles.card}>
              <Text style={styles.patientName}>{profName}</Text>
              <Text style={styles.patientContact}>E-mail: {session.professional.email}</Text>
              <Text style={styles.patientContact}>Papel Clínico: {session.professional.role}</Text>
              <Text style={styles.patientContact}>Clínica: {clinicName}</Text>

              {logoutError && (
                <Text accessibilityRole="alert" style={styles.errorText}>
                  {logoutError}
                </Text>
              )}

              <TouchableOpacity
                style={styles.logoutBtn}
                onPress={async () => {
                  setLoggingOut(true);
                  setLogoutError(null);
                  try {
                    await ApiService.logoutProfessional();
                    onLogout();
                  } catch {
                    setLogoutError('Não foi possível revogar a sessão no servidor. Tente novamente.');
                  } finally {
                    setLoggingOut(false);
                  }
                }}
                disabled={loggingOut}
                accessibilityRole="button"
                accessibilityLabel="Encerrar sessão profissional"
              >
                {loggingOut ? (
                  <ActivityIndicator color={t.white} />
                ) : (
                  <Text style={styles.logoutBtnText}>Encerrar Sessão (Logout)</Text>
                )}
              </TouchableOpacity>
            </View>
          </View>
        )}
      </ScrollView>

      {/* Barra de Navegação Inferior (WCAG AA) */}
      <View style={styles.navBar} accessibilityRole="tablist">
        {(['Agenda', 'Pacientes', 'Diarios', 'Chat', 'Perfil'] as ProfessionalTab[]).map((tab) => (
          <TouchableOpacity
            key={tab}
            style={[styles.navItem, currentTab === tab && styles.navItemActive]}
            onPress={() => setCurrentTab(tab)}
            accessibilityRole="tab"
            accessibilityState={{ selected: currentTab === tab }}
            accessibilityLabel={`Aba ${tab}`}
          >
            <Text style={[styles.navText, currentTab === tab && styles.navTextActive]}>{tab}</Text>
          </TouchableOpacity>
        ))}
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: t.background },
  centerContainer: { flex: 1, backgroundColor: t.background, justifyContent: 'center', alignItems: 'center', padding: 24 },
  loadingText: { marginTop: 14, fontSize: 15, color: t.navy, fontWeight: '600' },
  headerBar: {
    backgroundColor: t.navy,
    paddingHorizontal: 20,
    paddingVertical: 14,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  clinicBadge: { color: '#93C5FD', fontSize: 12, fontWeight: '600', textTransform: 'uppercase', letterSpacing: 0.5 },
  headerTitle: { color: t.white, fontSize: 18, fontWeight: '700', marginTop: 2 },
  statusIndicator: { backgroundColor: '#064E3B', paddingHorizontal: 10, paddingVertical: 4, borderRadius: 12 },
  statusText: { color: '#34D399', fontSize: 12, fontWeight: '700' },
  errorBanner: { backgroundColor: '#FEE2E2', padding: 12, margin: 16, borderRadius: t.cardRadius, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  errorText: { color: t.error, fontSize: 13, flex: 1, fontWeight: '500' },
  retryBtn: { backgroundColor: t.error, paddingHorizontal: 12, paddingVertical: 6, borderRadius: 6, minHeight: 44, justifyContent: 'center' },
  retryBtnText: { color: t.white, fontSize: 12, fontWeight: '700' },
  successBanner: { backgroundColor: '#D1FAE5', padding: 12, marginHorizontal: 16, marginTop: 10, borderRadius: t.cardRadius },
  successText: { color: '#065F46', fontSize: 13, fontWeight: '600' },
  scroll: { padding: 16, paddingBottom: 100 },
  sectionHeading: { fontSize: 20, fontWeight: '700', color: t.navy, marginBottom: 12 },
  helperText: { fontSize: 13, color: t.muted, marginBottom: 14 },
  metricsRow: { flexDirection: 'row', gap: 10, marginBottom: 16 },
  metricCard: { flex: 1, backgroundColor: t.white, borderRadius: t.cardRadius, padding: 12, alignItems: 'center', borderWidth: 1, borderColor: t.border },
  metricNumber: { fontSize: 22, fontWeight: '700', color: t.navy },
  metricLabel: { fontSize: 11, color: t.muted, marginTop: 4, textAlign: 'center', fontWeight: '500' },
  card: { backgroundColor: t.white, borderRadius: t.cardRadius, padding: 16, marginBottom: 14, borderWidth: 1, borderColor: t.border },
  cardHeaderRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 },
  cardTime: { fontSize: 14, fontWeight: '700', color: t.navy },
  cardTimeSmall: { fontSize: 12, color: t.muted },
  badgeStatus: { paddingHorizontal: 8, paddingVertical: 4, borderRadius: 6, fontSize: 12, fontWeight: '600' },
  badgeConfirmed: { backgroundColor: '#D1FAE5', color: '#065F46' },
  badgeScheduled: { backgroundColor: '#DBEAFE', color: '#1E40AF' },
  patientName: { fontSize: 16, fontWeight: '700', color: t.navy },
  patientContact: { fontSize: 13, color: t.muted, marginTop: 4 },
  patientFooter: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 10, paddingTop: 8, borderTopWidth: 1, borderTopColor: '#F3F4F6' },
  patientBadge: { fontSize: 12, color: t.ink, fontWeight: '500' },
  patientDate: { fontSize: 12, color: t.muted },
  cardNotes: { fontSize: 13, color: t.muted, marginTop: 6, fontStyle: 'italic' },
  cardAuthor: { fontSize: 14, fontWeight: '700', color: t.navy },
  mealTitle: { fontSize: 15, fontWeight: '600', color: t.ink, marginTop: 4 },
  mealDesc: { fontSize: 13, color: t.muted, marginTop: 4, lineHeight: 18 },
  feedbackGivenBox: { backgroundColor: '#F0FDF4', padding: 12, borderRadius: 8, marginTop: 10, borderLeftWidth: 3, borderLeftColor: '#16A34A' },
  feedbackGivenTitle: { fontSize: 12, fontWeight: '700', color: '#15803D' },
  feedbackGivenText: { fontSize: 13, color: t.ink, marginTop: 4, lineHeight: 18 },
  reviewedAtText: { fontSize: 11, color: t.muted, marginTop: 6, fontStyle: 'italic' },
  pendingFeedbackBox: { marginTop: 10, paddingTop: 8, borderTopWidth: 1, borderTopColor: '#F3F4F6' },
  pendingText: { fontSize: 12, color: '#D97706', fontWeight: '600', marginBottom: 8 },
  openFeedbackBtn: { backgroundColor: t.navy, paddingVertical: 10, borderRadius: 8, alignItems: 'center', minHeight: 44, justifyContent: 'center' },
  openFeedbackText: { color: t.white, fontSize: 13, fontWeight: '700' },
  feedbackForm: { marginTop: 8 },
  feedbackInput: { borderWidth: 1, borderColor: t.inputBorder, borderRadius: 8, padding: 10, fontSize: 13, color: t.ink, minHeight: 70, textAlignVertical: 'top', backgroundColor: '#FAFAFA' },
  formBtnRow: { flexDirection: 'row', gap: 10, justifyContent: 'flex-end', marginTop: 8 },
  cancelBtn: { paddingVertical: 10, paddingHorizontal: 14, borderRadius: 8, minHeight: 44, justifyContent: 'center' },
  cancelBtnText: { color: t.muted, fontSize: 13, fontWeight: '600' },
  submitBtn: { backgroundColor: t.copper, paddingVertical: 10, paddingHorizontal: 16, borderRadius: 8, minHeight: 44, justifyContent: 'center' },
  submitBtnText: { color: t.white, fontSize: 13, fontWeight: '700' },
  emptyContainer: { backgroundColor: t.white, borderRadius: t.cardRadius, padding: 24, alignItems: 'center', borderWidth: 1, borderColor: t.border, marginTop: 10 },
  emptyTitle: { fontSize: 16, fontWeight: '700', color: t.navy, marginBottom: 6 },
  emptyDescription: { fontSize: 13, color: t.muted, textAlign: 'center', lineHeight: 18 },
  searchContainer: { marginBottom: 12 },
  searchInput: { backgroundColor: t.white, borderWidth: 1, borderColor: t.inputBorder, borderRadius: 8, paddingHorizontal: 12, paddingVertical: 10, fontSize: 13, color: t.navy },
  paginationRow: { flexDirection: 'row', justifyContent: 'center', gap: 12, marginVertical: 14 },
  pageButton: { backgroundColor: t.navy, paddingVertical: 8, paddingHorizontal: 16, borderRadius: 6, minHeight: 44, justifyContent: 'center', alignItems: 'center' },
  pageButtonText: { color: t.white, fontSize: 13, fontWeight: '700' },
  logoutBtn: { backgroundColor: t.copper, padding: 14, borderRadius: t.buttonRadius, alignItems: 'center', marginTop: 16, minHeight: 44, justifyContent: 'center' },
  logoutBtnText: { color: t.white, fontSize: 15, fontWeight: '700' },
  navBar: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    height: 64,
    backgroundColor: t.white,
    borderTopWidth: 1,
    borderTopColor: t.border,
    flexDirection: 'row',
    justifyContent: 'space-around',
    alignItems: 'center',
  },
  navItem: { alignItems: 'center', justifyContent: 'center', minWidth: 60, minHeight: 44 },
  navItemActive: { borderBottomWidth: 3, borderBottomColor: t.copper },
  navText: { fontSize: 12, fontWeight: '500', color: t.muted },
  navTextActive: { color: t.navy, fontWeight: '700' },
});
