import React, { createContext, useContext, useRef, useState } from 'react';
import { Alert, Modal, Pressable, ScrollView, Switch, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import * as DocumentPicker from 'expo-document-picker';
import * as Location from 'expo-location';
import { SvgXml } from 'react-native-svg';
import type { Entry, FieldValue, NativeNode, Values } from './types';
import { fields, formEntries, initialValues, namedValues, validate } from './model';
import { qrSvg } from './print';
import { colors, styles as s } from './theme';

type Actions = {
  navigate: (href: string) => void;
  submit: (route: string, method: string, entries: Entry[]) => Promise<void>;
  print: () => void;
  busy: boolean;
  drafts: Map<string, Values>;
};
const ActionContext = createContext<Actions>(null!);
const FormContext = createContext<{
  values: Values; set: (key: string, value: FieldValue) => void;
  submit: (button: NativeNode) => void; locate: () => Promise<void>;
} | null>(null);

export function Button({ title, onPress, secondary, disabled, danger }: {
  title: string; onPress: () => void; secondary?: boolean; disabled?: boolean; danger?: boolean;
}) {
  return <Pressable accessibilityRole="button" accessibilityState={{ disabled: Boolean(disabled) }}
    disabled={disabled} onPress={onPress}
    style={({ pressed }) => [s.button, secondary && s.secondary, danger && s.danger, (disabled || pressed) && s.disabled]}>
    <Text style={[s.buttonText, secondary && s.secondaryText]}>{title}</Text>
  </Pressable>;
}

function Form({ node }: { node: NativeNode }) {
  const actions = useContext(ActionContext);
  const children = node.children ?? [];
  const allFields = fields(children);
  const signature = `${node.href}:${allFields.map(f => f.name + (f.type === 'hidden' && f.name !== 'csrf_token' ? '=' + f.value : '')).join('|')}`;
  const [values, setValues] = useState<Values>(() => initialValues(children, actions.drafts.get(signature)));
  const [error, setError] = useState('');
  const [locating, setLocating] = useState(false);
  const locked = useRef(false);
  const set = (key: string, value: FieldValue) => {
    setValues(previous => {
      const next = { ...previous, [key]: value };
      const field = allFields.find(f => f.key === key);
      if (field?.type === 'radio' && value) allFields.filter(f => f.name === field.name && f.key !== key).forEach(f => { next[f.key] = false; });
      actions.drafts.set(signature, namedValues(children, next));
      return next;
    });
  };
  const send = async (button: NativeNode) => {
    if (locked.current || actions.busy) return;
    const message = validate(children, values, button);
    if (message) { setError(message); Alert.alert('Check your form', message); return; }
    setError('');
    locked.current = true;
    try { await actions.submit(node.href!, (node.method ?? 'get').toUpperCase(), formEntries(children, values, button)); }
    finally { locked.current = false; }
  };
  const submit = (button: NativeNode) => {
    const sensitive = allFields.some(f => ['decision', 'declaration', 'payment_reference'].includes(f.name ?? ''));
    if (sensitive && !button.skipValidation) {
      Alert.alert('Confirm submission', 'Check the details and selected documents before continuing.', [
        { text: 'Keep editing', style: 'cancel' }, { text: 'Submit', onPress: () => void send(button) },
      ]);
    } else void send(button);
  };
  const locate = async () => {
    if (locating) return;
    setLocating(true);
    try {
      const permission = await Location.requestForegroundPermissionsAsync();
      if (!permission.granted) throw new Error('Location permission was declined. You can still enter your business address manually.');
      const position = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      const valuesByName: Record<string, string> = {
        latitude: String(position.coords.latitude), longitude: String(position.coords.longitude),
        location_accuracy_m: String(position.coords.accuracy ?? ''), location_updated: '1',
      };
      allFields.forEach(f => { if (f.name && valuesByName[f.name] !== undefined) set(f.key, valuesByName[f.name]); });
      Alert.alert('Location captured', `${position.coords.latitude.toFixed(5)}, ${position.coords.longitude.toFixed(5)}`);
    } catch (error) { Alert.alert('Location', error instanceof Error ? error.message : 'Could not capture location.'); }
    finally { setLocating(false); }
  };
  return <FormContext.Provider value={{ values, set, submit, locate }}>
    <View style={s.stack}>
      {error !== '' && <Text accessibilityRole="alert" style={s.error}>{error}</Text>}
      {locating && <Text style={s.notice}>Finding your location…</Text>}
      <Nodes nodes={children} />
    </View>
  </FormContext.Provider>;
}

function Field({ node }: { node: NativeNode }) {
  const form = useContext(FormContext);
  const { busy } = useContext(ActionContext);
  const [showOptions, setShowOptions] = useState(false);
  const [picking, setPicking] = useState(false);
  if (!form || node.type === 'hidden') return null;
  const value = form.values[node.key] ?? '';
  const disabled = node.disabled || node.readonly || busy;
  const text = typeof value === 'string' ? value : '';
  const label = (node.label ?? node.name ?? '') + (node.required ? ' *' : '');
  const change = (newValue: FieldValue) => form.set(node.key, newValue);
  const chooseFile = async () => {
    setPicking(true);
    try {
      const result = await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/jpeg', 'image/png'], copyToCacheDirectory: true });
      if (result.canceled) return;
      const file = result.assets[0];
      if (file.size !== undefined && file.size > 5 * 1024 * 1024) throw new Error('Choose a PDF, JPG, or PNG no larger than 5 MB.');
      change({ uri: file.uri, name: file.name, type: file.mimeType ?? 'application/octet-stream', size: file.size });
    } catch (error) { Alert.alert('Select document', error instanceof Error ? error.message : 'Could not open the file picker.'); }
    finally { setPicking(false); }
  };
  if (node.type === 'checkbox' || node.type === 'radio') return <View style={s.row}>
    <Switch accessibilityLabel={label} disabled={disabled} value={Boolean(value)} onValueChange={change} trackColor={{ true: colors.teal }} />
    <Text style={[s.text, s.grow]}>{label}</Text>
  </View>;
  return <View style={s.stack}>
    <Text style={s.label}>{label}</Text>
    {node.type === 'select' ? <>
      <Pressable accessibilityRole="button" accessibilityLabel={label} accessibilityState={{ disabled: Boolean(disabled) }}
        style={[s.input, s.row, disabled && s.disabled]} disabled={disabled} onPress={() => setShowOptions(true)}>
        <Text style={[s.text, s.grow]}>{node.options?.find(o => o.value === text)?.label ?? 'Choose an option'}</Text><Text style={s.text}>⌄</Text>
      </Pressable>
      <Modal visible={showOptions} animationType="slide" onRequestClose={() => setShowOptions(false)}>
        <SafeAreaView style={s.modal}>
          <View style={s.topbar}><Text style={[s.title, s.grow]}>{label}</Text><Button title="Done" secondary onPress={() => setShowOptions(false)} /></View>
          <ScrollView>{node.options?.map((option, index) => <Pressable key={index} accessibilityRole="radio"
            accessibilityState={{ checked: option.value === text, disabled: option.disabled }} disabled={option.disabled}
            style={[s.option, option.disabled && s.disabled]} onPress={() => { change(option.value); setShowOptions(false); }}>
            <Text style={s.text}>{option.value === text ? '✓  ' : ''}{option.label}</Text>
          </Pressable>)}</ScrollView>
        </SafeAreaView>
      </Modal>
    </> : node.type === 'file' ? <View style={s.stack}>
      <Button title={picking ? 'Opening files…' : typeof value === 'object' ? value.name : 'Choose PDF or image'} secondary disabled={disabled || picking} onPress={() => void chooseFile()} />
      {typeof value === 'object' && <Button title="Remove file" secondary disabled={disabled} onPress={() => change('')} />}
      <Text style={s.muted}>PDF, JPG, or PNG · Maximum 5 MB</Text>
    </View> : <>
      <TextInput accessibilityLabel={label} style={[s.input, node.type === 'textarea' && s.multiline, disabled && s.disabled]}
        value={text} onChangeText={change} editable={!disabled} secureTextEntry={node.type === 'password'}
        autoCapitalize={['email', 'password', 'date', 'datetime-local'].includes(node.type ?? '') ? 'none' : 'sentences'}
        autoCorrect={!['email', 'password', 'date', 'datetime-local'].includes(node.type ?? '')}
        autoComplete={node.type === 'email' ? 'email' : node.type === 'password' ? 'password' : 'off'}
        keyboardType={node.type === 'email' ? 'email-address' : node.type === 'tel' ? 'phone-pad' : node.inputMode === 'decimal' || node.type === 'number' ? 'decimal-pad' : 'default'}
        placeholder={node.placeholder || (node.type === 'date' ? 'YYYY-MM-DD' : node.type === 'datetime-local' ? 'YYYY-MM-DDTHH:mm' : '')}
        placeholderTextColor={colors.muted} maxLength={node.maxLength ?? undefined} multiline={node.type === 'textarea'} />
      {(node.type === 'date' || node.type === 'datetime-local') && <Text style={s.muted}>{node.type === 'date' ? 'Format: YYYY-MM-DD' : 'Local date and time: YYYY-MM-DDTHH:mm'}</Text>}
    </>}
  </View>;
}

function Details({ node }: { node: NativeNode }) {
  const [open, setOpen] = useState(false);
  const [summary, ...rest] = node.children ?? [];
  return <View style={s.stack}>
    <Pressable accessibilityRole="button" accessibilityState={{ expanded: open }} onPress={() => setOpen(!open)}>
      <Text style={s.link}>{open ? '−' : '+'} {summary?.text ?? 'Details'}</Text>
    </Pressable>
    {open && <Nodes nodes={rest} />}
  </View>;
}

function Node({ node }: { node: NativeNode }) {
  const actions = useContext(ActionContext);
  const form = useContext(FormContext);
  switch (node.kind) {
    case 'form': return <Form node={node} />;
    case 'field': return <Field node={node} />;
    case 'details': return <Details node={node} />;
    case 'qr': return <View accessibilityLabel="Permit verification QR code" style={{ alignItems: 'center', padding: 12 }}><SvgXml xml={qrSvg(node.text ?? '')} width={170} height={170} /></View>;
    case 'button': return <Button title={node.text ?? 'Continue'} secondary={node.tone === 'secondary'} danger={node.tone === 'danger'}
      disabled={actions.busy || node.disabled} onPress={() => {
        if (node.action === 'print') actions.print();
        else if (node.action === 'location') void form?.locate();
        else form?.submit(node);
      }} />;
    case 'link': return node.tone === 'button'
      ? <Button title={node.text ?? 'Open'} secondary disabled={actions.busy} onPress={() => actions.navigate(node.href!)} />
      : <Pressable accessibilityRole="link" disabled={actions.busy} onPress={() => actions.navigate(node.href!)}><Text style={s.link}>{node.text}</Text></Pressable>;
    case 'text': return <Text selectable style={[s.text,
      node.tone === 'heading' && s.heading, node.tone === 'bold' && s.bold, node.tone === 'muted' && s.muted,
      node.tone === 'badge' && s.badge, node.tone === 'error' && s.error, node.tone === 'notice' && s.notice,
    ]}>{node.text}</Text>;
    case 'table': return <View style={s.stack}>{node.rows?.map((row, index) => <View key={index} style={s.card}>
      {row.map((cell, cellIndex) => <View key={cellIndex} style={s.stack}>
        {node.headers?.[cellIndex] && <Text style={s.muted}>{node.headers[cellIndex]}</Text>}
        <Nodes nodes={cell} />
      </View>)}
    </View>)}</View>;
    default: return <View style={[s.stack, node.tone === 'card' && s.card, node.tone === 'notice' && s.notice, node.tone === 'error' && s.error]}><Nodes nodes={node.children ?? []} /></View>;
  }
}

function Nodes({ nodes }: { nodes: NativeNode[] }) {
  return <>{nodes.map(node => <Node key={node.key} node={node} />)}</>;
}

export function NativeScreen({ nodes, ...actions }: Actions & { nodes: NativeNode[] }) {
  return <ActionContext.Provider value={actions}><Nodes nodes={nodes} /></ActionContext.Provider>;
}
