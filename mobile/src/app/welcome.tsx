import AsyncStorage from '@react-native-async-storage/async-storage';
import { useRouter } from 'expo-router';
import { useRef, useState } from 'react';
import { Dimensions, FlatList, Pressable, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { CountedCrate, StampedDocket, TrackedRoute } from '../ui/illustrations';
import { Button, T } from '../ui/kit';
import { palette, space } from '../ui/theme';

const SCENES = [
  {
    key: 'approve',
    art: StampedDocket,
    kicker: 'Approvals',
    title: 'Decide on orders without going back to your desk',
    text: 'Purchase orders and requisitions waiting for you, with their lines and the reason they need you. Approve, or reject with a reason that is recorded.',
  },
  {
    key: 'receive',
    art: CountedCrate,
    kicker: 'Receiving',
    title: 'Count deliveries at the gate',
    text: "Scan item barcodes against the order, note what is short or damaged, and record the goods receipt while the supplier's vehicle is still there.",
  },
  {
    key: 'track',
    art: TrackedRoute,
    kicker: 'Tracking',
    title: 'Know where every order stands',
    text: 'Look up a purchase order or a supplier: what is approved, issued, received and billed, and what the books say you owe them.',
  },
];

/** The value screens before sign-in: what this app is for, in three scenes. */
export default function Welcome() {
  const router = useRouter();
  const [index, setIndex] = useState(0);
  const list = useRef<FlatList>(null);
  const width = Dimensions.get('window').width;

  const finish = async () => {
    await AsyncStorage.setItem('purchases.welcomed', '1').catch(() => undefined);
    router.replace('/sign-in');
  };

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: palette.paper }}>
      <View style={st.top}>
        <T v="label" color={palette.signalDeep}>
          Purchases · Aicountly
        </T>
        <Pressable accessibilityRole="button" onPress={finish} hitSlop={12}>
          <T v="label" color={palette.textSoft}>
            Skip
          </T>
        </Pressable>
      </View>
      <FlatList
        ref={list}
        data={SCENES}
        horizontal
        pagingEnabled
        showsHorizontalScrollIndicator={false}
        keyExtractor={(s) => s.key}
        onMomentumScrollEnd={(e) => setIndex(Math.round(e.nativeEvent.contentOffset.x / width))}
        renderItem={({ item }) => (
          <View style={{ width, paddingHorizontal: space.xl, justifyContent: 'center' }}>
            <View style={{ alignItems: 'center', marginBottom: space.xl }}>
              <item.art size={Math.min(240, width - 96)} />
            </View>
            <T v="label" color={palette.signalDeep}>
              {item.kicker}
            </T>
            <T v="display" style={{ marginTop: space.s }}>
              {item.title}
            </T>
            <T v="body" color={palette.textSoft} style={{ marginTop: space.m }}>
              {item.text}
            </T>
          </View>
        )}
      />
      <View style={st.foot}>
        <View style={{ flexDirection: 'row', gap: 6 }} accessibilityLabel={`Page ${index + 1} of ${SCENES.length}`}>
          {SCENES.map((s, i) => (
            <View key={s.key} style={[st.tick, i === index && { backgroundColor: palette.ink, width: 28 }]} />
          ))}
        </View>
        <Button
          label={index === SCENES.length - 1 ? 'Sign in' : 'Next'}
          kind={index === SCENES.length - 1 ? 'signal' : 'ink'}
          onPress={() => {
            if (index === SCENES.length - 1) return void finish();
            list.current?.scrollToIndex({ index: index + 1 });
            setIndex(index + 1);
          }}
          style={{ minWidth: 140 }}
        />
      </View>
    </SafeAreaView>
  );
}

const st = StyleSheet.create({
  top: { flexDirection: 'row', justifyContent: 'space-between', paddingHorizontal: space.xl, paddingTop: space.m },
  foot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', padding: space.xl },
  tick: { height: 4, width: 12, backgroundColor: palette.ruleStrong },
});
