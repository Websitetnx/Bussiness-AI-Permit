import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator, Alert, BackHandler, Image, KeyboardAvoidingView, Linking, Modal,
  Platform, Pressable, RefreshControl, ScrollView, Text, TextInput, View,
} from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { PermitApi, allowHttp } from './src/api';
import { Button, NativeScreen } from './src/NativeScreen';
import { homeRoute, internalRoute, isDownload } from './src/model';
import { sharePdf } from './src/print';
import { colors, styles as s } from './src/theme';
import type { Entry, Envelope, Notice, Values } from './src/types';

type Panel = 'screen' | 'notifications' | 'chat';

function PermitApp() {
  const api = useRef(new PermitApi()).current;
  const drafts = useRef(new Map<string, Values>()).current;
  const history = useRef<string[]>([]);
  const scroll = useRef<ScrollView>(null);
  const locked = useRef(false);
  const [response, setResponse] = useState<Envelope | null>(null);
  const [revision, setRevision] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [messages, setMessages] = useState<string[]>([]);
  const [menu, setMenu] = useState(false);
  const [settings, setSettings] = useState(false);
  const [server, setServer] = useState(process.env.EXPO_PUBLIC_API_URL ?? '');
  const [panel, setPanel] = useState<Panel>('screen');
  const [notices, setNotices] = useState<Notice[]>([]);
  const [chat, setChat] = useState<{ role: string; text: string }[]>([]);
  const [question, setQuestion] = useState('');
  const [suggestions, setSuggestions] = useState<string[]>([]);

  const task = async (action: () => Promise<void>) => {
    if (locked.current) return;
    locked.current = true;
    setBusy(true);
    setError('');
    try { await action(); }
    catch (exception) {
      const message = exception instanceof Error ? exception.message : 'Could not connect to the server.';
      setError(message);
      Alert.alert('Could not complete request', message);
    }
    finally { locked.current = false; setBusy(false); }
  };

  const load = async (route: string, method = 'GET', entries: Entry[] = [], replace = false) => {
    await task(async () => {
      const previous = response?.route;
      let result = await api.request(route, method, entries);
      const flashes: string[] = [];
      let redirects = 0;
      while (result.redirect) {
        flashes.push(...(result.messages ?? []).map(m => m.message));
        if (++redirects > 6) throw new Error('The server could not complete navigation. Please sign in again.');
        result = await api.request(result.redirect);
      }
      if (!result.screen) throw new Error('The server did not return a screen.');
      if (!result.user || !response?.user || result.user.id !== response.user.id) {
        history.current = [];
        drafts.clear();
        setNotices([]);
        setChat([]);
      } else if (!replace && previous && previous !== result.route) history.current.push(previous);
      if (redirects || method === 'GET') drafts.clear();
      setResponse(result);
      setRevision(value => value + 1);
      setMessages([...flashes, ...result.screen.messages]);
      setPanel('screen');
      setSettings(false);
      setMenu(false);
      scroll.current?.scrollTo({ y: 0, animated: false });
    });
  };

  useEffect(() => {
    void (async () => {
      try {
        if (await api.restore()) { setServer(api.baseUrl); await load('index.php', 'GET', [], true); }
        else setSettings(true);
      } catch (exception) {
        setError(exception instanceof Error ? exception.message : 'Could not restore the connection.');
        setSettings(true);
      }
    })();
    // Boot once; subsequent requests are explicit user navigation.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const follow = async (href: string, replace = false) => {
    const route = internalRoute(href, api.baseUrl);
    if (!route) {
      if (/^(https?:|mailto:|tel:)/i.test(href)) await task(async () => { await Linking.openURL(href); });
      else setError('This link is not available in the mobile app.');
    } else if (route === 'logout.php') {
      await load(route, 'POST', [], true);
    } else if (isDownload(route)) {
      await task(() => api.download(route));
    } else await load(route, 'GET', [], replace);
  };

  const navigate = (href: string, replace = false) => {
    if (busy) return;
    if (drafts.size) {
      Alert.alert('Leave this form?', 'Your unsent changes will be discarded.', [
        { text: 'Keep editing', style: 'cancel' },
        { text: 'Leave', onPress: () => { drafts.clear(); void follow(href, replace); } },
      ]);
    } else void follow(href, replace);
  };

  const back = useCallback(() => {
    if (locked.current) return true;
    if (menu) { setMenu(false); return true; }
    if (settings && response) { setSettings(false); return true; }
    if (panel !== 'screen') { setPanel('screen'); return true; }
    const last = history.current[history.current.length - 1];
    if (!last) return false;
    const go = () => { history.current.pop(); drafts.clear(); void load(last, 'GET', [], true); };
    if (drafts.size) Alert.alert('Leave this form?', 'Your unsent changes will be discarded.', [{ text: 'Keep editing', style: 'cancel' }, { text: 'Leave', onPress: go }]);
    else go();
    return true;
  }, [menu, settings, response, panel]);

  useEffect(() => {
    const listener = BackHandler.addEventListener('hardwareBackPress', back);
    return () => listener.remove();
  }, [back]);

  const configure = async () => {
    try {
      await api.configure(server);
      history.current = [];
      drafts.clear();
      setResponse(null);
      setSettings(false);
      await load('login.php', 'GET', [], true);
    } catch (exception) { setError(exception instanceof Error ? exception.message : 'Invalid server URL.'); }
  };

  const notifications = async (markAll = false) => {
    await task(async () => {
      if (markAll) await api.request('api/notifications.php', 'POST', [['action', 'mark_all_read']]);
      const result = await api.request('api/notifications.php?action=list');
      if (result.redirect || !result.data?.ok) throw new Error('Your session expired. Return home and sign in again.');
      setNotices((result.data.notifications ?? []) as Notice[]);
      setPanel('notifications');
      setMenu(false);
      scroll.current?.scrollTo({ y: 0, animated: false });
    });
  };

  const readNotice = async (notice: Notice) => {
    await task(async () => {
      const result = await api.request('api/notifications.php', 'POST', [['action', 'mark_read'], ['id', String(notice.id)]]);
      if (!result.data?.ok) throw new Error('Could not mark this notification as read.');
      setNotices(previous => previous.map(item => item.id === notice.id ? { ...item, is_read: true } : item));
    });
    if (notice.detail_url) navigate(notice.detail_url);
  };

  const ask = async (message = question) => {
    if (!message.trim()) return;
    await task(async () => {
      const result = await api.request('api/chatbot.php', 'POST', [['message', message.trim()]]);
      if (!result.data?.ok) throw new Error('The help assistant is unavailable.');
      setChat(previous => [...previous, { role: 'You', text: message.trim() }, { role: 'BPLO assistant', text: String(result.data?.reply ?? '') }]);
      setSuggestions((result.data.suggestions ?? []) as string[]);
      setQuestion('');
    });
  };

  const user = response?.user;
  const isStaff = Boolean(user && ['admin', 'staff', 'superadmin', 'treasurer'].includes(user.role));
  const screen = response?.screen;
  const title = panel === 'notifications' ? 'Notifications' : panel === 'chat' ? 'BPLO help' : screen?.title ?? 'Welcome to PERMIT';

  return <SafeAreaView style={s.root} edges={['top', 'left', 'right', 'bottom']}>
    <StatusBar style="dark" />
    <View style={s.topbar}>
      {(history.current.length > 0 || panel !== 'screen') && <Pressable accessibilityRole="button" accessibilityLabel="Go back" style={s.iconButton} onPress={back}><Text style={s.iconText}>‹</Text></Pressable>}
      <View style={s.grow}><Text style={s.eyebrow}>CITY GOVERNMENT SERVICES</Text><Text style={s.title}>{title}</Text></View>
      <Pressable accessibilityRole="button" accessibilityLabel="Open menu" style={s.iconButton} onPress={() => setMenu(true)}><Text style={s.iconText}>☰</Text></Pressable>
    </View>
    {busy && <View accessibilityLiveRegion="polite" style={[s.row, { padding: 10, justifyContent: 'center', backgroundColor: colors.soft }]}>
      <ActivityIndicator color={colors.primary} /><Text style={s.muted}>Working… uploads and scans may take a moment.</Text>
    </View>}
    <KeyboardAvoidingView style={s.grow} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView ref={scroll} contentContainerStyle={s.content} keyboardShouldPersistTaps="handled"
        refreshControl={response ? <RefreshControl refreshing={false} enabled={!busy && drafts.size === 0} onRefresh={() => {
          if (panel === 'notifications') void notifications(); else if (panel === 'screen') void load(response.route, 'GET', [], true);
        }} /> : undefined}>
        {error !== '' && <View style={s.stack}><Text accessibilityRole="alert" style={s.error}>{error}</Text>
          <Button title="Retry loading screen" secondary disabled={busy} onPress={() => void load(response?.route ?? 'login.php', 'GET', [], true)} />
        </View>}
        {messages.map((message, index) => <Text key={index} style={s.notice}>{message}</Text>)}
        {panel === 'screen' && <>
          {!user && <View style={s.hero}><Image source={require('./assets/logo-light.png')} style={s.logo} accessibilityLabel="PERMIT" />
            <Text style={s.heroText}>Your business.{ '\n' }Your next chapter.</Text>
            <Text style={s.heroSmall}>Apply, renew, and keep track of your business permit wherever you are.</Text>
          </View>}
          {screen && <NativeScreen key={revision} nodes={screen.nodes} drafts={drafts} busy={busy} navigate={navigate}
            submit={(route, method, entries) => load(route, method, entries)} print={() => void task(() => sharePdf(screen))} />}
          {!screen && !busy && <Button title="Connect to your PERMIT server" onPress={() => setSettings(true)} />}
        </>}
        {panel === 'notifications' && <>
          <Button title="Mark all read" secondary disabled={busy} onPress={() => void notifications(true)} />
          {notices.length === 0 && <View style={s.card}><Text style={s.heading}>You’re all caught up</Text><Text style={s.muted}>Application and payment updates will appear here.</Text></View>}
          {notices.map(notice => <View key={notice.id} style={s.card}>
            {!notice.is_read && <Text style={s.badge}>New update</Text>}
            <Text style={s.text}>{notice.message}</Text><Text style={s.muted}>{notice.time_ago}</Text>
            <Button title={notice.detail_url ? notice.action_label : 'Mark as read'} secondary disabled={busy || (notice.is_read && !notice.detail_url)} onPress={() => void readNotice(notice)} />
          </View>)}
        </>}
        {panel === 'chat' && <>
          <View style={s.card}><Text style={s.heading}>How can we help?</Text><Text style={s.text}>Ask about requirements, permits, payments, or an application reference.</Text></View>
          {chat.map((message, index) => <View key={index} style={[s.card, message.role === 'You' && s.notice]}><Text style={s.label}>{message.role}</Text><Text selectable style={s.text}>{message.text}</Text></View>)}
          {suggestions.map(item => <Button key={item} title={item} secondary disabled={busy} onPress={() => void ask(item)} />)}
          <TextInput accessibilityLabel="Message to BPLO assistant" value={question} onChangeText={setQuestion} editable={!busy} placeholder="Type your question" placeholderTextColor={colors.muted} multiline style={[s.input, s.multiline]} />
          <Button title="Send message" disabled={busy || !question.trim()} onPress={() => void ask()} />
        </>}
      </ScrollView>
    </KeyboardAvoidingView>
    {user && <View style={s.tabs}>
      {[
        { label: 'Home', symbol: '⌂', press: () => navigate(homeRoute(user.role), true) },
        { label: 'Payments', symbol: '₱', press: () => navigate(isStaff ? 'admin/payments.php' : 'payments.php', true) },
        { label: 'Updates', symbol: '◉', press: () => void notifications() },
        { label: 'Help', symbol: '?', press: () => { setPanel('chat'); setError(''); } },
      ].map(tab => <Pressable key={tab.label} accessibilityRole="button" disabled={busy} style={s.tab} onPress={tab.press}>
        <Text style={s.iconText}>{tab.symbol}</Text><Text style={s.tabLabel}>{tab.label}</Text>
      </Pressable>)}
    </View>}
    <Modal visible={menu} animationType="slide" onRequestClose={() => setMenu(false)}>
      <SafeAreaView style={s.modal}>
        <View style={s.topbar}><Text style={[s.title, s.grow]}>Your workspace</Text><Button title="Close" secondary onPress={() => setMenu(false)} /></View>
        <ScrollView contentContainerStyle={s.content}>
          {user && <View style={s.card}><Text style={s.heading}>{user.name}</Text><Text style={s.muted}>{user.email} · {user.role}</Text></View>}
          {(screen?.navigation ?? []).map((link, index) => <Button key={index} title={link.label} secondary disabled={busy} onPress={() => { setMenu(false); navigate(link.href); }} />)}
          <Button title="Connection settings" secondary disabled={busy} onPress={() => { setMenu(false); setSettings(true); }} />
        </ScrollView>
      </SafeAreaView>
    </Modal>
    <Modal visible={settings} animationType="slide" onRequestClose={() => { if (response) setSettings(false); }}>
      <SafeAreaView style={s.modal}>
        <ScrollView contentContainerStyle={s.content} keyboardShouldPersistTaps="handled">
          <Text style={s.eyebrow}>PERMIT MOBILE</Text><Text style={s.heading}>Connect to your office</Text>
          <Text style={s.text}>Enter the address of your PERMIT installation. Ask your system administrator if you’re unsure.</Text>
          <Text style={s.label}>Server address</Text>
          <TextInput accessibilityLabel="PERMIT server address" autoCapitalize="none" autoCorrect={false} keyboardType="url" value={server} onChangeText={setServer} style={s.input} placeholder="https://permits.example.gov" placeholderTextColor={colors.muted} />
          {allowHttp && <Text style={s.muted}>Development: Android emulator uses 10.0.2.2. A physical phone needs the computer’s LAN IP address.</Text>}
          {error !== '' && <Text style={s.error}>{error}</Text>}
          <Button title="Save and connect" disabled={busy} onPress={() => void configure()} />
          {response && <Button title="Cancel" secondary onPress={() => setSettings(false)} />}
        </ScrollView>
      </SafeAreaView>
    </Modal>
  </SafeAreaView>;
}

export default function App() {
  return <SafeAreaProvider><PermitApp /></SafeAreaProvider>;
}
