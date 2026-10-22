/**
 * NI2-95: aviso no celular pela Expo Push Service.
 *
 * - Só roda em aparelho de verdade (nada no navegador nem no simulador).
 * - Pede permissão uma vez; se a paciente negar, segue a vida sem insistir.
 * - O projectId vem do app.json (expo.extra.eas.projectId). Sem ele, não cadastra nada.
 * - O token vai para o servidor depois do login e é desligado no logout (ApiService.logout).
 * - O texto que chega é neutro, sem dado de saúde; o conteúdo fica dentro do app.
 */
import { Platform } from 'react-native';
import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { ApiService } from './api';

let handlerInstalled = false;

function installHandler(): void {
  if (handlerInstalled) return;
  handlerInstalled = true;
  Notifications.setNotificationHandler({
    handleNotification: async () => ({ shouldShowAlert: true, shouldPlaySound: true, shouldSetBadge: false }),
  });
}

function projectId(): string | null {
  const fromConfig = (Constants.expoConfig?.extra as { eas?: { projectId?: unknown } } | undefined)?.eas?.projectId;
  const fromEas = (Constants as unknown as { easConfig?: { projectId?: unknown } }).easConfig?.projectId;
  const id = typeof fromConfig === 'string' && fromConfig ? fromConfig : typeof fromEas === 'string' && fromEas ? fromEas : null;
  return id;
}

export type PushSetupResult = 'registered' | 'unsupported' | 'denied' | 'no_project' | 'failed';

/** Pede permissão (se ainda não decidida), pega o token e cadastra no servidor. Nunca lança erro. */
export async function setupPushNotifications(): Promise<PushSetupResult> {
  if (Platform.OS === 'web' || !Device.isDevice) return 'unsupported';
  try {
    installHandler();
    if (Platform.OS === 'android') {
      await Notifications.setNotificationChannelAsync('default', {
        name: 'Avisos do consultório',
        importance: Notifications.AndroidImportance.DEFAULT,
      });
    }
    let { status } = await Notifications.getPermissionsAsync();
    if (status !== 'granted') {
      ({ status } = await Notifications.requestPermissionsAsync());
    }
    if (status !== 'granted') return 'denied';

    const id = projectId();
    if (!id) {
      console.warn('[push] expo.extra.eas.projectId não configurado; aviso no celular desligado.');
      return 'no_project';
    }
    const { data: token } = await Notifications.getExpoPushTokenAsync({ projectId: id });
    await ApiService.registerPushToken(token, Platform.OS === 'ios' || Platform.OS === 'android' ? Platform.OS : 'unknown');
    return 'registered';
  } catch (_) {
    return 'failed';
  }
}

/** Ao tocar no aviso, diz qual aba abrir (chat ou a lista de avisos). Devolve a função para desligar o ouvinte. */
export function onPushOpened(open: (category: string | null) => void): () => void {
  if (Platform.OS === 'web') return () => {};
  const sub = Notifications.addNotificationResponseReceivedListener((response) => {
    const data = response.notification.request.content.data as { category?: unknown } | undefined;
    open(typeof data?.category === 'string' ? data.category : null);
  });
  return () => sub.remove();
}
