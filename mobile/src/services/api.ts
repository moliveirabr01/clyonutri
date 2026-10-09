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
let currentApiBaseUrl: string = (process.env.EXPO_PUBLIC_API_URL || 'http://localhost:8080').replace(/\/+$/, '');
let onSessionExpired: (() => void) | null = null;

export function setSessionExpiredHandler(handler: (() => void) | null) {
  onSessionExpired = handler;
}

export class ApiError extends Error {
  constructor(message: string, public status: number) { super(message); }
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

  if (response.status === 401 && currentAuthToken && endpoint !== '/api/v1/auth/patient-login') {
    setAuthToken(null);
    onSessionExpired?.();
  }

  if (!response.ok) {
    let errorDetail = `HTTP ${response.status} ${response.statusText}`;
    try {
      const errJson = await response.json();
      if (errJson?.error) {
        errorDetail = errJson.error;
      }
    } catch (_) {
      // Falha ao parsear corpo de erro JSON
    }
    throw new ApiError(errorDetail, response.status);
  }

  try {
    return await response.json() as T;
  } catch (_) {
    throw new ApiError('O servidor retornou uma resposta inválida. Tente novamente.', response.status);
  }
}

export const ApiService = {
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
    return request<PatientHomeData>('/api/v1/patient/home');
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
    return request<{ entries: FoodDiaryEntry[] }>('/api/v1/patient/food-diary');
  },

  async logMeal(entry: { meal_name: string; description?: string; consumed_at?: string }): Promise<FoodDiaryEntry> {
    return request<FoodDiaryEntry>('/api/v1/patient/food-diary', {
      method: 'POST',
      body: JSON.stringify(entry),
    });
  },

  async getEvolution(): Promise<EvolutionData> {
    return request<EvolutionData>('/api/v1/patient/evolution');
  },

  async logSelfMetric(metric: { metric_type: string; value: number; unit?: string; notes?: string }) {
    return request('/api/v1/patient/self-metrics', {
      method: 'POST',
      body: JSON.stringify(metric),
    });
  },
};
