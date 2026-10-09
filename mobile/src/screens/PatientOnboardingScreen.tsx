import React, { useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, SafeAreaView, ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import BrandLogo from '../components/BrandLogo';
import { ApiService, PatientAuthData, PatientInvitationPreview } from '../services/api';
import { tokens as t } from '../theme/tokens';

export default function PatientOnboardingScreen({ onBack, onActivated, initialCode = '' }: { onBack: () => void; onActivated: (auth: PatientAuthData) => void; initialCode?: string }) {
  const [code, setCode] = useState(initialCode);
  const [preview, setPreview] = useState<PatientInvitationPreview | null>(null);
  const [name, setName] = useState(''); const [email, setEmail] = useState(''); const [phone, setPhone] = useState('');
  const [password, setPassword] = useState(''); const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false); const [error, setError] = useState('');
  const [activated, setActivated] = useState<PatientAuthData | null>(null);
  async function next() {
    if (busy) return;
    setBusy(true); setError('');
    try {
      if (!preview) {
        const data = await ApiService.previewPatientInvitation(code.trim());
        setPreview(data); setName(data.patient.name); setEmail(data.patient.email); setPhone(data.patient.phone);
      } else {
        if (password.length < 8 || password !== confirmation) throw new Error('Use pelo menos 8 caracteres e confirme a mesma senha.');
        const auth = await ApiService.completePatientOnboarding({ invitation_code: code.trim(), name, email, phone, password, password_confirmation: confirmation });
        setCode(''); setPassword(''); setConfirmation(''); setActivated(auth);
      }
    } catch (e) { setError(e instanceof Error ? e.message : 'Não foi possível concluir. Tente novamente.'); }
    finally { setBusy(false); }
  }
  return <SafeAreaView style={s.page}><KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
    <ScrollView contentContainerStyle={s.content} keyboardShouldPersistTaps="handled"><View style={s.card}>
      <View style={s.brand}><BrandLogo light /><Text style={s.caption}>Seu cuidado começa aqui.</Text></View>
      <View style={s.body}>
        <Text accessibilityRole="header" style={s.title}>{activated ? 'Tudo pronto!' : 'Primeiro acesso do paciente'}</Text>
        {activated ? <>
          <Text style={s.description}>Bem-vindo(a), {activated.patient.name}. Seu acesso foi ativado.</Text>
          <Text style={s.description}>No app, você poderá consultar seu plano alimentar, acompanhar suas consultas e registrar refeições no diário. Os dados aparecerão conforme seu nutricionista os disponibilizar.</Text>
          <TouchableOpacity accessibilityRole="button" style={s.button} onPress={() => onActivated(activated)}><Text style={s.buttonText}>Começar acompanhamento</Text></TouchableOpacity>
        </> : <>
          <Text style={s.description}>{preview ? `Confira seu cadastro em ${preview.clinic_name}. Nutricionista: ${preview.professional_name}.` : 'Peça um convite ao seu nutricionista e cole o código abaixo. O convite é pessoal e vale por 48 horas.'}</Text>
          <Text style={s.description}>{preview ? 'Etapa 2 de 2 · Cadastro e senha' : 'Etapa 1 de 2 · Seu convite'}</Text>
          {!preview ? <><Text style={s.label}>Código do convite</Text><TextInput accessibilityLabel="Código do convite" style={s.input} value={code} onChangeText={setCode} autoCapitalize="none" autoCorrect={false} maxLength={64} editable={!busy} placeholder="Cole o código recebido" /></> : <>
            <Text style={s.label}>Nome completo</Text><TextInput accessibilityLabel="Nome completo" style={s.input} value={name} onChangeText={setName} maxLength={255} editable={!busy} autoComplete="name" />
            <Text style={s.label}>E-mail</Text><TextInput accessibilityLabel="E-mail para acessar o app" style={s.input} value={email} onChangeText={setEmail} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" autoComplete="email" maxLength={255} editable={!busy} />
            <Text style={s.label}>Celular (opcional)</Text><TextInput accessibilityLabel="Celular" style={s.input} value={phone} onChangeText={setPhone} keyboardType="phone-pad" maxLength={30} editable={!busy} />
            <Text style={s.label}>Crie sua senha</Text><Text style={s.description}>Use pelo menos 8 caracteres.</Text><TextInput accessibilityLabel="Nova senha do paciente" style={s.input} value={password} onChangeText={setPassword} secureTextEntry autoComplete="new-password" editable={!busy} />
            <Text style={s.label}>Confirme sua senha</Text><TextInput accessibilityLabel="Confirmação da senha" style={s.input} value={confirmation} onChangeText={setConfirmation} secureTextEntry autoComplete="new-password" editable={!busy} />
          </>}
          {!!error && <Text accessibilityRole="alert" style={s.error}>{error}</Text>}
          <TouchableOpacity accessibilityRole="button" accessibilityState={{ disabled: busy, busy }} disabled={busy} style={s.button} onPress={next}>{busy ? <ActivityIndicator color={t.white} /> : <Text style={s.buttonText}>{preview ? 'Ativar meu acesso' : 'Conferir convite'}</Text>}</TouchableOpacity>
          {preview && <TouchableOpacity accessibilityRole="button" disabled={busy} style={s.back} onPress={() => { setPreview(null); setPassword(''); setConfirmation(''); setError(''); }}><Text style={s.link}>Usar outro convite</Text></TouchableOpacity>}
          <TouchableOpacity accessibilityRole="button" disabled={busy} style={s.back} onPress={onBack}><Text style={s.link}>Voltar para entrar</Text></TouchableOpacity>
        </>}
      </View>
    </View></ScrollView>
  </KeyboardAvoidingView></SafeAreaView>;
}
const s = StyleSheet.create({
  page: { flex: 1, backgroundColor: t.background }, content: { flexGrow: 1, alignItems: 'center', justifyContent: 'center', padding: 20 },
  card: { width: '100%', maxWidth: 460, backgroundColor: t.white, borderRadius: 20, overflow: 'hidden', borderWidth: 1, borderColor: t.border },
  brand: { padding: 24, backgroundColor: t.navy, alignItems: 'center', gap: 10, borderTopWidth: 4, borderTopColor: '#E67E3A' }, caption: { color: '#D7E1E9' },
  body: { padding: 24, gap: 12 }, title: { fontSize: 23, fontWeight: '700', color: t.navy }, description: { fontSize: 14, lineHeight: 22, color: t.muted },
  label: { fontWeight: '600', color: t.navy }, input: { borderWidth: 1, borderColor: t.border, borderRadius: 8, padding: 12, minHeight: 48, fontSize: 16, color: t.ink },
  button: { backgroundColor: t.copper, padding: 14, borderRadius: 10, minHeight: 48, alignItems: 'center', justifyContent: 'center' }, buttonText: { color: t.white, fontWeight: '600', fontSize: 16 },
  back: { minHeight: 44, alignItems: 'center', justifyContent: 'center' }, link: { color: t.navy, fontWeight: '600' }, error: { color: t.error, fontSize: 14, lineHeight: 22 },
});
