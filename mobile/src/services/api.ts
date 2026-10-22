/**
 * ClyoNutri Mobile API Service (NI2-176)
 * Comunicação direta com a API REST /api/v1 do ClyoNutri II
 */

export interface PatientAuthData {
  token: string;
  expires_at: string;
  patient: {
    id: number;
    clinic_id: number;
    name: string;
    email: string;
  };
}

export interface PatientInvitationPreview { patient: { name: string; email: string; phone: string }; clinic_name: string; professional_name: string; }

export interface ProfessionalAuthData {
  token: string;
  expires_at: string;
  professional: {
    id: number;
    clinic_id: number;
    clinic_name: string;
    name: string;
    email: string;
    role: string;
  };
}

export interface ProfessionalDashboardData {
  professional_name: string;
  clinic_name: string;
  clinic_id: number;
  today_stats: {
    total: number;
    completed: number;
    in_progress: number;
    scheduled: number;
    confirmed: number;
    cancelled: number;
    no_show: number;
  };
  today_appointments: Array<{
    id: number;
    scheduled_at: string;
    status: string;
    patient_name?: string;
    patient_id?: number;
    notes?: string;
  }>;
  pending_diaries_count: number;
  active_patients_count: number;
}

export interface ProfessionalPatientItem {
  id: number;
  name: string;
  email: string;
  phone?: string;
  status: string;
  created_at: string;
}

export interface ProfessionalFoodDiaryItem {
  id: number;
  clinic_id: number;
  patient_id: number;
  patient_name: string;
  patient_email: string;
  meal_name: string;
  consumed_at: string;
  photo_path?: string;
  description?: string;
  hunger_level?: number;
  satiety_level?: number;
  symptoms?: string;
  feedback_from_professional?: string;
  reviewed_at?: string;
}

/** Mensagem do chat (NI2-138): lista fechada de campos; datas em ISO 8601 com o fuso do consultório. */
export interface ChatMessage {
  id: number;
  conversation_id: number;
  sender_type: 'patient' | 'professional';
  message: string;
  status: 'sent' | 'delivered' | 'read';
  created_at: string | null;
  delivered_at?: string | null;
  read_at?: string | null;
  has_attachment?: boolean;
  attachment?: { id: number; name: string; mime_type: string; size_bytes: number } | null;
  replayed?: boolean;
}

export interface ChatAccess { can_send: boolean; code: string | null; reason: string | null }
export interface ChatNotice { response_time: string | null; response_time_text: string; emergency_text: string }
export interface ChatChannel { professional_id: number; professional_name: string; unread_count: number; can_start: boolean }

export interface ChatPollResponse {
  conversation_id: number | null;
  professional?: {
    id: number;
    name: string;
    crn?: string | null;
  } | null;
  channels?: ChatChannel[];
  /** Status atual das últimas mensagens de quem lê (Enviada → Entregue → Lida). */
  receipts?: Array<{ id: number; status: ChatMessage['status'] }>;
  access?: ChatAccess | null;
  notice?: ChatNotice;
  messages: ChatMessage[];
  cursor: {
    after_id: number;
    count: number;
    has_more: boolean;
  };
  polling_policy?: {
    active_window_ms: number;
    background_window_ms: number;
  };
}

/** Arquivo escolhido para anexar (app: uri; web: File). */
export interface ChatAttachmentInput { uri: string; name: string; mimeType: string; file?: Blob | null }

export interface ProfessionalChatConversation {
  id: number;
  patient_id: number;
  patient_name: string;
  patient_email?: string;
  last_message?: string;
  last_message_at?: string;
  unread_count?: number;
}

export interface MealPlanItemSubstitution {
  id: number;
  item_id: number;
  food_id: number;
  food_name: string;
  amount?: number;
  measure_unit?: string;
  notes?: string | null;
}

export interface MealPlanItem {
  id: number;
  meal_id: number;
  food_id: number;
  food_name: string;
  quantity: number;
  unit: string;
  energy_kcal?: number;
  protein_g?: number;
  carbohydrate_g?: number;
  lipid_g?: number;
  notes?: string | null;
  substitutions?: MealPlanItemSubstitution[];
}

export interface MealPlanMeal {
  id: number;
  meal_plan_id: number;
  name: string;
  time: string;
  ordering: number;
  notes?: string | null;
  items: MealPlanItem[];
}

export interface ActiveMealPlan {
  id: number;
  clinic_id: number;
  patient_id: number;
  title: string;
  status: string;
  version_number?: number;
  version?: number;
  published_at?: string | null;
  notes?: string;
  meals: MealPlanMeal[];
}

export interface AppointmentActionState { allowed: boolean; reason: string | null; deadline_iso?: string }
export interface RescheduleRequest {
  id: number; appointment_id: number;
  status: 'pending' | 'accepted' | 'declined' | 'cancelled' | 'expired'; status_label: string;
  from_scheduled_at_iso: string; requested_scheduled_at_iso: string;
  reason: string | null; decision_note: string | null; created_at_iso: string; decided_at_iso: string | null;
}
/** Próxima consulta (NI2-137): lista fechada de campos emitida por PatientAppointmentService::nextAppointment. */
export interface Appointment {
  id: number;
  /** Hora local do consultório, sem fuso (compatibilidade). Prefira scheduled_at_iso. */
  scheduled_at: string;
  scheduled_at_iso?: string;
  ends_at_iso?: string;
  timezone?: string;
  timezone_label?: string;
  duration_minutes?: number;
  status: 'scheduled' | 'confirmed' | 'cancelled';
  status_label?: string;
  modality?: 'presencial' | 'online';
  modality_label?: string;
  professional_name?: string | null;
  clinic_name?: string | null;
  clinic_phone?: string | null;
  location?: string | null;
  meeting_url?: string | null;
  video_opens_at_iso?: string | null;
  is_online?: boolean;
  video_status?: 'presencial' | 'aguardando_horario' | 'aguardando_sala' | 'sala_disponivel';
  allowed_actions?: { confirm: AppointmentActionState; cancel: AppointmentActionState; reschedule: AppointmentActionState };
  change_request?: RescheduleRequest | null;
}
export interface RescheduleOptions {
  appointment_id: number; timezone: string; timezone_label: string; duration_minutes: number;
  days: Array<{ date: string; slots: Array<{ scheduled_at: string; scheduled_at_iso: string; time_label: string }> }>;
}
export interface AppointmentActionResult { success: boolean; message?: string; status?: string; request?: RescheduleRequest }

export interface PatientRecipePrescription {
  id: number; recipe_id: number; recipe_title: string; recipe_summary?: string;
  prescribed_servings: number; meal_name?: string; instructions_override?: string;
  scaled_energy_kcal: number;
}
export interface PatientRecipeDetails {
  title: string; instructions: string; servings: number;
  ingredients: Array<{ id: number; food_name: string; amount: number; measure_unit: string }>;
}

export interface PatientConsent { purpose: string; term_title: string | null; term_version: string | null; granted: boolean; granted_at: string | null; revoked_at: string | null; revocation_reason: string | null; }
export interface PrivacyRequest { id: number; request_type: string; status: string; deadline_at: string; completed_at?: string | null; response_notes?: string | null; created_at: string; already_open?: boolean; }
export interface PatientPrivacyData { consents: PatientConsent[]; requests: PrivacyRequest[]; }
// NI2-95: avisos e preferências de aviso.
export type NotificationCategory = 'chat' | 'appointments' | 'plan';
export interface PatientNotification { id: number; event_type: string; category: NotificationCategory; title: string; body: string; link: string | null; created_at: string; read: boolean; }
export interface NotificationInbox { items: PatientNotification[]; unread_count: number; next_before_id: number | null; }
export interface NotificationCategoryPref { category: NotificationCategory; label: string; email_enabled: boolean; push_enabled: boolean; }
export interface NotificationPreferences { categories: NotificationCategoryPref[]; quiet_hours: { enabled: boolean; start: string; end: string }; timezone: string; push_available: boolean; }
export interface NotificationPreferencesUpdate {
  categories?: Partial<Record<NotificationCategory, { email_enabled?: boolean; push_enabled?: boolean }>>;
  quiet_hours?: { enabled?: boolean; start?: string; end?: string };
}

export interface PatientHomeData {
  patient_name: string;
  water_ml_today: number;
  water_goal_ml: number | null;
  next_appointment: Appointment | null;
  active_plan: ActiveMealPlan | null;
}

export interface FoodDiaryEntry {
  id: number;
  patient_id: number;
  meal_name: string;
  consumed_at: string;
  description?: string;
  photo_path?: string;
  hunger_level?: number;
  satiety_level?: number;
  symptoms?: string;
  feedback_from_professional?: string;
  reviewed_at?: string;
}

export interface EvolutionMetric { type: string; label: string; unit: string; min: number; max: number; decimals: number; }
export interface EvolutionGoal {
  id: number; metric_type: string; label: string; target_value: number; unit: string; direction: string; direction_label: string;
  target_date: string | null; message_for_patient: string | null; status: string;
  latest_clinical: { date: string; value: number } | null; latest_self_report: { date: string; value: number } | null;
}
export interface EvolutionData {
  patient_id: number;
  period?: { from: string | null; to: string | null };
  clinical_records: Array<{
    id: number;
    date: string;
    weight_kg?: number | null;
    height_cm?: number | null;
    bmi?: number | null;
    waist_cm?: number | null;
    hip_cm?: number | null;
    source: string;
    source_label?: string;
  }>;
  self_reports: Array<{
    id: number;
    date: string;
    metric_type: string;
    label?: string;
    value: number;
    unit: string;
    notes?: string | null;
    source: string;
    source_label?: string;
  }>;
  series?: Array<{ type: string; label: string; unit: string; points: Array<{ date: string; value: number; source: string }> }>;
  enabled_metrics?: EvolutionMetric[];
  goals?: EvolutionGoal[];
}

let currentAuthToken: string | null = null;
let defaultBaseUrl = 'http://localhost:8080';
if (typeof window !== 'undefined' && window.location && window.location.hostname) {
  defaultBaseUrl = window.location.origin;
}
let currentApiBaseUrl: string = (process.env.EXPO_PUBLIC_API_URL || defaultBaseUrl).replace(/\/+$/, '');
let onSessionExpired: (() => void) | null = null;
/** NI2-95: token do Expo Push deste aparelho, guardado só em memória para desligar no logout. */
let currentPushToken: string | null = null;

export function setSessionExpiredHandler(handler: (() => void) | null) {
  onSessionExpired = handler;
}

export class ApiError extends Error {
  constructor(message: string, public status: number, public kind: 'offline' | 'server' | 'invalid' | 'auth' | 'timeout' | 'unavailable' = status === 0 ? 'offline' : status === 408 ? 'timeout' : status === 401 ? 'auth' : 'server', public requestId?: string, public code?: string) { super(message); }
}

export function setApiBaseUrl(url: string) {
  currentApiBaseUrl = url.replace(/\/+$/, '');
}

export function getApiBaseUrl(): string {
  return currentApiBaseUrl;
}

export function setAuthToken(token: string | null) {
  currentAuthToken = token;
}

export function getAuthToken(): string | null {
  return currentAuthToken;
}

async function request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const isLogin = endpoint === '/api/v1/auth/patient-login' || endpoint === '/api/v1/auth/professional-login';
  // Envio de arquivo (FormData): o navegador/RN define o Content-Type com o boundary.
  const isForm = typeof FormData !== 'undefined' && options.body instanceof FormData;
  const headers: Record<string, string> = {
    'Accept': 'application/json',
    ...(isForm ? {} : { 'Content-Type': 'application/json' }),
    ...(typeof window !== 'undefined' ? { 'X-ClyoNutri-Web': '1' } : {}),
    ...(options.headers as Record<string, string> || {}),
  };

  if (currentAuthToken) {
    headers['Authorization'] = `Bearer ${currentAuthToken}`;
  }

  const url = `${currentApiBaseUrl}${endpoint}`;
  let response: Response;
  let payload: any;
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 15000);

  try {
    response = await fetch(url, { ...options, credentials: 'include', headers, signal: controller.signal });
    if (response.status === 401 && (currentAuthToken || typeof window !== 'undefined') && !isLogin && endpoint !== '/api/v1/auth/session') {
      setAuthToken(null);
      onSessionExpired?.();
    }
    try { payload = await response.json(); }
    catch (error: any) {
      if (error?.name === 'AbortError') throw error;
      throw new ApiError('O servidor retornou uma resposta inválida. Tente novamente.', response.status, 'invalid', response.headers.get('X-Request-ID') || undefined);
    }
  } catch (err: any) {
    if (err instanceof ApiError) throw err;
    if (err?.name === 'AbortError') {
      throw new ApiError('A requisição excedeu o tempo limite (15s). Verifique sua conexão e tente novamente.', 408, 'timeout');
    }
    throw new ApiError('Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.', 0);
  } finally {
    clearTimeout(timeoutId);
  }

  if (!response.ok) {
    const message = response.status === 401 ? (isLogin ? 'E-mail ou senha incorretos.' : 'Sua sessão expirou. Entre novamente.')
      : response.status >= 500 ? 'Não foi possível carregar os dados. Tente novamente.'
      : typeof payload?.error === 'string' ? payload.error : 'Não foi possível concluir a solicitação.';
    throw new ApiError(message, response.status, response.status === 401 ? 'auth' : payload?.code === 'prescription_content_unavailable' ? 'unavailable' : 'server', payload?.request_id || response.headers.get('X-Request-ID') || undefined, typeof payload?.code === 'string' ? payload.code : undefined);
  }
  if (!payload || typeof payload !== 'object' || payload.error) {
    throw new ApiError('O servidor retornou uma resposta inválida. Tente novamente.', response.status, 'invalid');
  }
  return payload as T;
}

/** Chave de idempotência de uma mensagem (gerada uma vez, quando a mensagem é criada). */
export function newChatKey(prefix = 'mob'): string {
  return `${prefix}_${Date.now()}_${Math.random().toString(36).substring(2, 12)}`;
}

export const ApiService = {
  async restoreWebSession(): Promise<PatientAuthData | ProfessionalAuthData> {
    const data = await request<PatientAuthData | ProfessionalAuthData>('/api/v1/auth/session');
    if (!data.expires_at || !(('patient' in data && data.patient?.id) || ('professional' in data && data.professional?.id))) throw new ApiError('Resposta de sessão inválida.', 200, 'invalid');
    return data;
  },
  async getAccessHelp(): Promise<{ support_email: string | null }> {
    return request('/api/v1/auth/access-help');
  },
  async previewPatientInvitation(invitation_code: string): Promise<PatientInvitationPreview> {
    const data = await request<PatientInvitationPreview>('/api/v1/auth/patient-onboarding/preview', { method: 'POST', body: JSON.stringify({ invitation_code }) });
    if (typeof data.patient?.name !== 'string' || typeof data.patient.email !== 'string' || typeof data.patient.phone !== 'string' || typeof data.clinic_name !== 'string' || typeof data.professional_name !== 'string') throw new ApiError('Resposta do convite inválida. Tente novamente.', 200, 'invalid');
    return data;
  },
  async completePatientOnboarding(input: { invitation_code: string; name: string; email: string; phone: string; password: string; password_confirmation: string }): Promise<PatientAuthData> {
    const data = await request<PatientAuthData>('/api/v1/auth/patient-onboarding/complete', { method: 'POST', body: JSON.stringify(input) });
    if (!data.token || !data.patient?.id || !data.expires_at) throw new Error('Resposta de ativação inválida.');
    setAuthToken(data.token);
    return data;
  },
  async createPatientInvitation(patientId: number): Promise<{ invitation_code: string; expires_at: string }> {
    return request(`/api/v1/professional/patients/${patientId}/app-invitation`, { method: 'POST' });
  },

  async login(credentials: { email: string; password: string }): Promise<PatientAuthData> {
    const data = await request<PatientAuthData>('/api/v1/auth/patient-login', {
      method: 'POST',
      body: JSON.stringify(credentials),
    });
    if (!data.token || !data.patient?.id || !data.expires_at) {
      throw new Error('Resposta de autenticação inválida.');
    }
    setAuthToken(data.token);
    return data;
  },

  async getPrivacy(): Promise<PatientPrivacyData> {
    const data = await request<PatientPrivacyData>('/api/v1/patient/privacy');
    if (!Array.isArray(data.consents) || !Array.isArray(data.requests)) throw new ApiError('Resposta de privacidade inválida.', 200, 'invalid');
    return data;
  },
  async createPrivacyRequest(request_type: 'export' | 'deletion', details?: string): Promise<PrivacyRequest> {
    const data = await request<{ request: PrivacyRequest }>('/api/v1/patient/privacy-requests', { method: 'POST', body: JSON.stringify({ request_type, details }) });
    if (!data.request?.id) throw new ApiError('Resposta do pedido inválida.', 200, 'invalid');
    return data.request;
  },
  async getNotifications(beforeId?: number | null): Promise<NotificationInbox> {
    const data = await request<NotificationInbox>('/api/v1/patient/notifications' + (beforeId ? `?before_id=${beforeId}` : ''));
    if (!Array.isArray(data.items)) throw new ApiError('Resposta de avisos inválida.', 200, 'invalid');
    return data;
  },
  async markNotificationRead(id: number): Promise<{ unread_count: number }> {
    return request<{ unread_count: number }>(`/api/v1/patient/notifications/${id}/read`, { method: 'POST', body: '{}' });
  },
  async markAllNotificationsRead(): Promise<{ unread_count: number }> {
    return request<{ unread_count: number }>('/api/v1/patient/notifications/read-all', { method: 'POST', body: '{}' });
  },
  async getNotificationPreferences(): Promise<NotificationPreferences> {
    const data = await request<NotificationPreferences>('/api/v1/patient/notification-preferences');
    if (!Array.isArray(data.categories) || !data.quiet_hours) throw new ApiError('Resposta de preferências inválida.', 200, 'invalid');
    return data;
  },
  async saveNotificationPreferences(update: NotificationPreferencesUpdate): Promise<NotificationPreferences> {
    return request<NotificationPreferences>('/api/v1/patient/notification-preferences', { method: 'POST', body: JSON.stringify(update) });
  },
  /** Encerra a sessão só neste aparelho, sem depender da rede (code review NI2-91, M3). */
  forgetLocalSession(): void {
    setAuthToken(null);
  },

  /** NI2-95: cadastra este aparelho para o aviso no celular. */
  async registerPushToken(token: string, platform: string): Promise<{ registered: boolean; push_enabled: boolean }> {
    const res = await request<{ registered: boolean; push_enabled: boolean }>('/api/v1/patient/push-tokens', { method: 'POST', body: JSON.stringify({ token, platform }) });
    currentPushToken = token;
    return res;
  },
  /** NI2-95: desliga o aviso no celular deste aparelho. Nunca impede o logout. */
  async removePushToken(): Promise<void> {
    const token = currentPushToken;
    if (!token || !getAuthToken()) return;
    try {
      await request<{ removed: boolean }>('/api/v1/patient/push-tokens/remove', { method: 'POST', body: JSON.stringify({ token }) });
      currentPushToken = null;
    } catch (_) {
      // Sem rede ou sessão já expirada: o servidor desliga o token sozinho quando o Expo avisar que ele não vale mais.
    }
  },

  async logout(): Promise<{ message: string }> {
    // Keep the token on transport failure so the patient can retry server revocation.
    await ApiService.removePushToken();
    try {
      const res = await request<{ message: string }>('/api/v1/auth/patient-logout', {
        method: 'POST',
      });
      setAuthToken(null);
      return res;
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setAuthToken(null);
        return { message: 'Sessão já encerrada ou expirada.' };
      }
      throw error;
    }
  },

  async getHome(): Promise<PatientHomeData> {
    const data = await request<PatientHomeData>('/api/v1/patient/home');
    if (typeof data.patient_name !== 'string' || typeof data.water_ml_today !== 'number' || !('active_plan' in data)) throw new ApiError('Resposta do resumo inválida. Tente novamente.', 200, 'invalid');
    return data;
  },

  async logHydration(amountMl: number): Promise<{ id: number; amount_ml: number; total_water_today: number }> {
    return request<{ id: number; amount_ml: number; total_water_today: number }>('/api/v1/patient/hydration', {
      method: 'POST',
      body: JSON.stringify({ amount_ml: amountMl }),
    });
  },

  /** NI2-137: o servidor responde 2xx só quando a consulta mudou de fato; qualquer recusa vem como erro com código. */
  async confirmAppointment(appointmentId: number): Promise<AppointmentActionResult> {
    const data = await request<AppointmentActionResult>(`/api/v1/patient/appointments/${appointmentId}/confirm`, { method: 'POST', body: '{}' });
    if (data.success !== true) throw new ApiError('Não foi possível confirmar. Atualize a tela e tente de novo.', 200, 'invalid');
    return data;
  },
  async cancelAppointment(appointmentId: number, reason: string): Promise<AppointmentActionResult> {
    const data = await request<AppointmentActionResult>(`/api/v1/patient/appointments/${appointmentId}/cancel`, { method: 'POST', body: JSON.stringify({ reason }) });
    if (data.success !== true) throw new ApiError('Não foi possível cancelar. Atualize a tela e tente de novo.', 200, 'invalid');
    return data;
  },
  async getRescheduleOptions(appointmentId: number): Promise<RescheduleOptions> {
    const data = await request<RescheduleOptions>(`/api/v1/patient/appointments/${appointmentId}/reschedule-options`);
    if (!Array.isArray(data.days)) throw new ApiError('Resposta de horários inválida. Tente novamente.', 200, 'invalid');
    return data;
  },
  async requestReschedule(appointmentId: number, requestedScheduledAt: string, reason: string): Promise<AppointmentActionResult> {
    const data = await request<AppointmentActionResult>(`/api/v1/patient/appointments/${appointmentId}/reschedule-requests`, { method: 'POST', body: JSON.stringify({ requested_scheduled_at: requestedScheduledAt, reason }) });
    if (data.success !== true || !data.request) throw new ApiError('Não foi possível enviar o pedido. Atualize a tela e tente de novo.', 200, 'invalid');
    return data;
  },
  async withdrawRescheduleRequest(requestId: number): Promise<AppointmentActionResult> {
    const data = await request<AppointmentActionResult>(`/api/v1/patient/reschedule-requests/${requestId}/cancel`, { method: 'POST', body: '{}' });
    if (data.success !== true) throw new ApiError('Não foi possível retirar o pedido. Atualize a tela e tente de novo.', 200, 'invalid');
    return data;
  },

  async getFoodDiary(): Promise<{ entries: FoodDiaryEntry[] }> {
    const data = await request<{ entries: FoodDiaryEntry[] }>('/api/v1/patient/food-diary');
    if (!Array.isArray(data.entries)) throw new ApiError('Resposta do diário inválida. Tente novamente.', 200, 'invalid');
    return data;
  },

  async logMeal(entry: { meal_name: string; description?: string; consumed_at?: string; idempotency_key?: string }): Promise<FoodDiaryEntry> {
    return request<FoodDiaryEntry>('/api/v1/patient/food-diary', {
      method: 'POST',
      body: JSON.stringify(entry),
    });
  },

  async getRecipes(): Promise<PatientRecipePrescription[]> {
    const response = await request<{ data: PatientRecipePrescription[] }>('/api/v1/patient/recipes');
    if (!Array.isArray(response.data)) throw new ApiError('Resposta das receitas inválida. Tente novamente.', 200, 'invalid');
    return response.data;
  },

  async getRecipe(id: number, prescriptionId?: number): Promise<PatientRecipeDetails> {
    const response = await request<{ data: PatientRecipeDetails }>('/api/v1/patient/recipes/' + id + (prescriptionId === undefined ? '' : '?prescription_id=' + prescriptionId));
    return response.data;
  },

  async getEvolution(from?: string | null): Promise<EvolutionData> {
    const data = await request<EvolutionData>('/api/v1/patient/evolution' + (from ? '?from=' + encodeURIComponent(from) : ''));
    if (!Array.isArray(data.clinical_records) || !Array.isArray(data.self_reports)) throw new ApiError('Resposta da evolução inválida. Tente novamente.', 200, 'invalid');
    return data;
  },

  async logSelfMetric(metric: { metric_type: string; value: number; unit?: string; notes?: string }): Promise<{ id: number; value: number; unit: string; label: string; recorded_at: string }> {
    return request('/api/v1/patient/self-metrics', {
      method: 'POST',
      body: JSON.stringify(metric),
    });
  },

  // --- Métodos da Jornada do Nutricionista / Profissional (NI2-292) ---
  async loginProfessional(credentials: { email: string; password: string }): Promise<ProfessionalAuthData> {
    const data = await request<ProfessionalAuthData>('/api/v1/auth/professional-login', {
      method: 'POST',
      body: JSON.stringify(credentials),
    });
    if (!data.token || !data.professional?.id || !data.expires_at) {
      throw new Error('Resposta de autenticação profissional inválida.');
    }
    setAuthToken(data.token);
    return data;
  },

  async logoutProfessional(): Promise<{ message: string }> {
    try {
      const res = await request<{ message: string }>('/api/v1/auth/professional-logout', {
        method: 'POST',
      });
      setAuthToken(null);
      return res;
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setAuthToken(null);
        return { message: 'Sessão profissional já encerrada ou expirada.' };
      }
      throw error;
    }
  },

  async getProfessionalDashboard(): Promise<ProfessionalDashboardData> {
    return request<ProfessionalDashboardData>('/api/v1/professional/dashboard');
  },

  async getProfessionalPatients(search?: string, page: number = 1, limit: number = 50): Promise<{ patients: ProfessionalPatientItem[]; total?: number; page?: number; limit?: number; has_more?: boolean }> {
    const params = new URLSearchParams();
    if (search && search.trim() !== '') {
      params.append('search', search.trim());
    }
    if (page > 1) {
      params.append('page', String(page));
    }
    if (limit !== 50) {
      params.append('limit', String(limit));
    }
    const qs = params.toString();
    const endpoint = `/api/v1/professional/patients${qs ? `?${qs}` : ''}`;
    return request<{ patients: ProfessionalPatientItem[]; total?: number; page?: number; limit?: number; has_more?: boolean }>(endpoint);
  },

  async getProfessionalFoodDiaries(): Promise<{ diaries: ProfessionalFoodDiaryItem[] }> {
    return request<{ diaries: ProfessionalFoodDiaryItem[] }>('/api/v1/professional/food-diaries');
  },

  async submitFoodDiaryFeedback(entryId: number, feedback: string): Promise<{ success: boolean; entry_id: number; feedback: string; reviewed_at: string }> {
    return request<{ success: boolean; entry_id: number; feedback: string; reviewed_at: string }>(`/api/v1/professional/food-diary/${entryId}/feedback`, {
      method: 'POST',
      body: JSON.stringify({ feedback }),
    });
  },

  // --- Métodos de Chat e Mensageria Segura (NI2-189) ---
  async getChatMessages(afterId?: number, professionalId?: number): Promise<ChatPollResponse> {
    const q = [afterId && afterId > 0 ? `after_id=${afterId}` : '', professionalId ? `professional_id=${professionalId}` : ''].filter(Boolean).join('&');
    const data = await request<ChatPollResponse>('/api/v1/patient/chat/messages' + (q ? `?${q}` : ''));
    if (!Array.isArray(data.messages)) throw new ApiError('Resposta do chat inválida. Tente novamente.', 200, 'invalid');
    return data;
  },

  /**
   * NI2-138 (A6): a chave de idempotência vem de quem criou a mensagem e é a mesma em toda tentativa,
   * então reenviar depois de um erro de rede nunca duplica. Com anexo, o envio é multipart.
   */
  async sendChatMessage(message: string, conversationId?: number, idempotencyKey?: string, attachment?: ChatAttachmentInput | null, professionalId?: number): Promise<{ success: boolean; message: ChatMessage }> {
    const key = idempotencyKey || newChatKey();
    let body: string | FormData;
    if (attachment) {
      const form = new FormData();
      form.append('message', message);
      form.append('idempotency_key', key);
      if (conversationId) form.append('conversation_id', String(conversationId));
      if (professionalId) form.append('professional_id', String(professionalId));
      if (attachment.file) form.append('attachment', attachment.file as Blob, attachment.name);
      else form.append('attachment', { uri: attachment.uri, name: attachment.name, type: attachment.mimeType } as unknown as Blob);
      body = form;
    } else {
      body = JSON.stringify({ message, conversation_id: conversationId, professional_id: professionalId, idempotency_key: key });
    }
    const data = await request<{ success: boolean; message: ChatMessage }>('/api/v1/patient/chat/send', { method: 'POST', body });
    if (data.success !== true || !data.message) throw new ApiError('Não foi possível enviar. Tente de novo.', 200, 'invalid');
    return data;
  },

  /** Link curto (2 min) para abrir o anexo no navegador ou no sistema, sem levar o token da sessão. */
  async getChatAttachmentUrl(attachmentId: number): Promise<string> {
    const data = await request<{ url: string; expires_in: number }>(`/api/v1/patient/chat/attachment/${attachmentId}/link`, { method: 'POST', body: '{}' });
    if (typeof data.url !== 'string' || !data.url.startsWith('/api/v1/chat/anexo/')) throw new ApiError('Não foi possível abrir o anexo.', 200, 'invalid');
    return `${currentApiBaseUrl}${data.url}`;
  },

  async markChatAsRead(conversationId: number): Promise<{ success: boolean }> {
    return request<{ success: boolean }>('/api/v1/patient/chat/read', {
      method: 'POST',
      body: JSON.stringify({ conversation_id: conversationId }),
    });
  },

  async getProfessionalChatConversations(): Promise<{ conversations: ProfessionalChatConversation[] }> {
    return request<{ conversations: ProfessionalChatConversation[] }>('/api/v1/professional/chat/conversations');
  },

  async getProfessionalChatMessages(conversationId: number, afterId?: number): Promise<ChatPollResponse> {
    const url = afterId && afterId > 0
      ? `/api/v1/professional/chat/${conversationId}/messages?after_id=${afterId}`
      : `/api/v1/professional/chat/${conversationId}/messages`;
    return request<ChatPollResponse>(url);
  },

  async sendProfessionalChatMessage(conversationId: number, message: string, idempotencyKey?: string): Promise<{ success: boolean; message: ChatMessage }> {
    return request<{ success: boolean; message: ChatMessage }>(`/api/v1/professional/chat/${conversationId}/send`, {
      method: 'POST',
      body: JSON.stringify({
        message,
        idempotency_key: idempotencyKey || newChatKey('prof'),
      }),
    });
  },

  async markProfessionalChatAsRead(conversationId: number): Promise<{ success: boolean }> {
    return request<{ success: boolean }>(`/api/v1/professional/chat/${conversationId}/read`, {
      method: 'POST',
    });
  },
};
