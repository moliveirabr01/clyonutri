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

export interface MealPlanItemSubstitution {
  id: number;
  item_id: number;
  food_id: number;
  food_name: string;
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
  substitutions?: MealPlanItemSubstitution[];
}

export interface MealPlanMeal {
  id: number;
  meal_plan_id: number;
  name: string;
  time: string;
  ordering: number;
  items: MealPlanItem[];
}

export interface ActiveMealPlan {
  id: number;
  clinic_id: number;
  patient_id: number;
  title: string;
  status: string;
  version_number?: number;
  notes?: string;
  meals: MealPlanMeal[];
}

export interface Appointment {
  id: number;
  clinic_id: number;
  patient_id: number;
  scheduled_at: string;
  status: 'scheduled' | 'confirmed' | 'cancelled';
  notes?: string;
  professional_name?: string;
  meeting_url?: string;
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

export interface EvolutionData {
  patient_id: number;
  clinical_records: Array<{
    id: number;
    date: string;
    weight_kg?: number;
    height_cm?: number;
    bmi?: number;
    notes?: string;
    source: string;
  }>;
  self_reports: Array<{
    id: number;
    date: string;
    metric_type: string;
    value: number;
    unit: string;
    notes?: string;
    source: string;
  }>;
}

let currentAuthToken: string | null = null;
let defaultBaseUrl = 'http://localhost:8080';
if (typeof window !== 'undefined' && window.location && window.location.hostname) {
  defaultBaseUrl = window.location.origin;
}
let currentApiBaseUrl: string = (process.env.EXPO_PUBLIC_API_URL || defaultBaseUrl).replace(/\/+$/, '');
let onSessionExpired: (() => void) | null = null;

export function setSessionExpiredHandler(handler: (() => void) | null) {
  onSessionExpired = handler;
}

export class ApiError extends Error {
  constructor(message: string, public status: number, public kind: 'offline' | 'server' | 'invalid' | 'auth' = status === 0 ? 'offline' : status === 401 ? 'auth' : 'server', public requestId?: string) { super(message); }
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
  const headers: Record<string, string> = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    ...(options.headers as Record<string, string> || {}),
  };

  if (currentAuthToken) {
    headers['Authorization'] = `Bearer ${currentAuthToken}`;
  }

  const url = `${currentApiBaseUrl}${endpoint}`;
  let response: Response;
  try {
    response = await fetch(url, { ...options, headers });
  } catch (_) {
    throw new ApiError('Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.', 0);
  }

  if (response.status === 401 && currentAuthToken && !isLogin) {
    setAuthToken(null);
    onSessionExpired?.();
  }

  let payload: any;
  try { payload = await response.json(); }
  catch (_) {
    throw new ApiError('O servidor retornou uma resposta inválida. Tente novamente.', response.status, 'invalid', response.headers.get('X-Request-ID') || undefined);
  }
  if (!response.ok) {
    const message = response.status === 401 ? (isLogin ? 'E-mail ou senha incorretos.' : 'Sua sessão expirou. Entre novamente.')
      : response.status >= 500 ? 'Não foi possível carregar os dados. Tente novamente.'
      : typeof payload?.error === 'string' ? payload.error : 'Não foi possível concluir a solicitação.';
    throw new ApiError(message, response.status, response.status === 401 ? 'auth' : 'server', payload?.request_id || response.headers.get('X-Request-ID') || undefined);
  }
  if (!payload || typeof payload !== 'object' || payload.error) {
    throw new ApiError('O servidor retornou uma resposta inválida. Tente novamente.', response.status, 'invalid');
  }
  return payload as T;
}

export const ApiService = {
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

  async logout(): Promise<{ message: string }> {
    // Keep the token on transport failure so the patient can retry server revocation.
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

  async confirmAppointment(appointmentId: number): Promise<{ success: boolean }> {
    return request<{ success: boolean }>('/api/v1/patient/appointment/confirm', {
      method: 'POST',
      body: JSON.stringify({ appointment_id: appointmentId }),
    });
  },

  async getFoodDiary(): Promise<{ entries: FoodDiaryEntry[] }> {
    const data = await request<{ entries: FoodDiaryEntry[] }>('/api/v1/patient/food-diary');
    if (!Array.isArray(data.entries)) throw new ApiError('Resposta do diário inválida. Tente novamente.', 200, 'invalid');
    return data;
  },

  async logMeal(entry: { meal_name: string; description?: string; consumed_at?: string }): Promise<FoodDiaryEntry> {
    return request<FoodDiaryEntry>('/api/v1/patient/food-diary', {
      method: 'POST',
      body: JSON.stringify(entry),
    });
  },

  async getEvolution(): Promise<EvolutionData> {
    const data = await request<EvolutionData>('/api/v1/patient/evolution');
    if (!Array.isArray(data.clinical_records) || !Array.isArray(data.self_reports)) throw new ApiError('Resposta da evolução inválida. Tente novamente.', 200, 'invalid');
    return data;
  },

  async logSelfMetric(metric: { metric_type: string; value: number; unit?: string; notes?: string }) {
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

  async getProfessionalPatients(): Promise<{ patients: ProfessionalPatientItem[] }> {
    return request<{ patients: ProfessionalPatientItem[] }>('/api/v1/professional/patients');
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
};
