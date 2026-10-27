const localHttp = process.env.EXPO_PUBLIC_ALLOW_HTTP === 'true';

export default {
  expo: {
    name: 'PERMIT',
    slug: 'permit-mobile',
    platforms: ['ios', 'android'],
    version: '1.0.0',
    orientation: 'default',
    userInterfaceStyle: 'light',
    ios: {
      supportsTablet: true,
      bundleIdentifier: 'com.permit.mobile',
      infoPlist: localHttp ? { NSAppTransportSecurity: { NSAllowsArbitraryLoads: true } } : {},
    },
    android: { package: 'com.permit.mobile' },
    plugins: [
      'expo-secure-store',
      'expo-sharing',
      ['expo-location', { locationWhenInUsePermission: 'Allow PERMIT to attach your business location to an application.' }],
      ['expo-build-properties', { android: { usesCleartextTraffic: localHttp } }],
    ],
  },
};
