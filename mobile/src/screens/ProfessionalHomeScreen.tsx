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
  TextInput,
  Alert,
} from 'react-native';
import { tokens as t } from '../theme/tokens';
import {
  ApiService,
  ApiError,
  ProfessionalAuthData,
  ProfessionalDashboardData,
  ProfessionalPatientItem,
  ProfessionalFoodDiaryItem,
} from '../services/api';

export type ProfessionalTab = 'Agenda' | 'Pacientes' | 'Diarios' | 'Perfil';

export default function ProfessionalHomeScreen({
  session,
  onLogout,
}: {
  session: ProfessionalAuthData;
  onLogout: () => void;
}) {
  const [currentTab, setCurrentTab] = useState<ProfessionalTab>('Agenda');
  const [loading, setLoading] = useState<boolean>(true);
  const [refreshing, setRefreshing] = useState<boolean>(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Dados reais carregados da API
  const [dashboard, setDashboard] = useState<ProfessionalDashboardData | null>(null);
  const [patients, setPatients] = useState<ProfessionalPatientItem[]>([]);
  const [diaries, setDiaries] = useState<ProfessionalFoodDiaryItem[]>([]);

  // Estado para feedback em diário
  const [selectedEntryId, setSelectedEntryId] = useState<number | null>(null);
  const [feedbackText, setFeedbackText] = useState<string>('');
  const [sendingFeedback, setSendingFeedback] = useState<boolean>(false);
  const [feedbackSuccess, setFeedbackSuccess] = useState<string | null>(null);

  const [loggingOut, setLoggingOut] = useState<boolean>(false);
  const [logoutError, setLogoutError] = useState<string | null>(null);

  const fetchDashboard = useCallback(async () => {
    try {
      const data = await ApiService.getProfessionalDashboard();
      setDashboard(data);
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'auth') return;
      setErrorMessage('Não foi possível carregar o dashboard profissional.');
    }
  }, []);

  const fetchPatients = useCallback(async () => {
    try {
      const res = await ApiService.getProfessionalPatients();
      setPatients(res.patients);
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'auth') return;
      setErrorMessage('Não foi possível carregar a lista de pacientes.');
    }
  }, []);

  const fetchDiaries = useCallback(async () => {
    try {
      const res = await ApiService.getProfessionalFoodDiaries();
      setDiaries(res.diaries);
    } catch (err) {
      if (err instanceof ApiError && err.kind === 'auth') return;
      setErrorMessage('Não foi possível carregar os diários alimentares.');
    }
  }, []);

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

  const onRefresh = useCallback(() => {
    setRefreshing(true);
    fetchAllData();
  }, [fetchAllData]);

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

            {/* Lista de Atendimentos */}
            {dashboard && dashboard.today_appointments && dashboard.today_appointments.length > 0 ? (
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

        {/* ABA 2: PACIENTES */}
        {currentTab === 'Pacientes' && (
          <View>
            <Text style={styles.sectionHeading} accessibilityRole="header">
              Pacientes Cadastrados ({patients.length})
            </Text>
            {patients.length > 0 ? (
              patients.map((p) => (
                <View key={p.id} style={styles.card}>
                  <Text style={styles.patientName}>{p.name}</Text>
                  <Text style={styles.patientContact}>📧 {p.email}</Text>
                  {p.phone && <Text style={styles.patientContact}>📱 {p.phone}</Text>}
                  <View style={styles.patientFooter}>
                    <Text style={styles.patientBadge}>Status: {p.status}</Text>
                    <Text style={styles.patientDate}>Desde: {p.created_at.substring(0, 10)}</Text>
                  </View>
                </View>
              ))
            ) : (
              <View style={styles.emptyContainer}>
                <Text style={styles.emptyTitle}>Nenhum paciente cadastrado</Text>
                <Text style={styles.emptyDescription}>
                  Cadastre novos pacientes através do portal ou envie convites de onboarding.
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
                    <Text style={styles.cardTimeSmall}>{d.consumed_at}</Text>
                  </View>
                  <Text style={styles.mealTitle}>🍽️ {d.meal_name}</Text>
                  {d.description && <Text style={styles.mealDesc}>{d.description}</Text>}

                  {/* Feedback existente */}
                  {d.feedback_from_professional ? (
                    <View style={styles.feedbackGivenBox}>
                      <Text style={styles.feedbackGivenTitle}>✓ Seu feedback clínico:</Text>
                      <Text style={styles.feedbackGivenText}>{d.feedback_from_professional}</Text>
                      {d.reviewed_at && (
                        <Text style={styles.reviewedAtText}>Revisado em: {d.reviewed_at}</Text>
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
          </View>
        )}

        {/* ABA 4: PERFIL & SEGURANÇA */}
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
        {(['Agenda', 'Pacientes', 'Diarios', 'Perfil'] as ProfessionalTab[]).map((tab) => (
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
  feedbackInput: { borderWidth: 1, borderColor: t.border, borderRadius: 8, padding: 10, fontSize: 13, color: t.ink, minHeight: 70, textAlignVertical: 'top', backgroundColor: '#FAFAFA' },
  formBtnRow: { flexDirection: 'row', gap: 10, justifyContent: 'flex-end', marginTop: 8 },
  cancelBtn: { paddingVertical: 10, paddingHorizontal: 14, borderRadius: 8, minHeight: 44, justifyContent: 'center' },
  cancelBtnText: { color: t.muted, fontSize: 13, fontWeight: '600' },
  submitBtn: { backgroundColor: t.copper, paddingVertical: 10, paddingHorizontal: 16, borderRadius: 8, minHeight: 44, justifyContent: 'center' },
  submitBtnText: { color: t.white, fontSize: 13, fontWeight: '700' },
  emptyContainer: { backgroundColor: t.white, borderRadius: t.cardRadius, padding: 24, alignItems: 'center', borderWidth: 1, borderColor: t.border, marginTop: 10 },
  emptyTitle: { fontSize: 16, fontWeight: '700', color: t.navy, marginBottom: 6 },
  emptyDescription: { fontSize: 13, color: t.muted, textAlign: 'center', lineHeight: 18 },
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
