/* =========================================================================
   DŹWIĘKI OTWIERANIA SKRZYNEK — syntezowane w locie przez Web Audio API
   (bez plików audio). Ta sama implementacja co w darmowej skrzynce i w
   bitwach (battle.html), wydzielona tutaj żeby płatne skrzynki brzmiały
   identycznie.
   ========================================================================= */
let soundOn = true;
let audioCtx = null;
let spinSoundStop = null;

function getAudioCtx(){
  if(!audioCtx){
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if(Ctx) audioCtx = new Ctx();
  }
  if(audioCtx && audioCtx.state === "suspended") audioCtx.resume();
  return audioCtx;
}

function playTone(freq, startTime, duration, {type="sine", gain=0.18, glideTo=null} = {}){
  const ctx = getAudioCtx();
  if(!ctx) return;
  const osc = ctx.createOscillator();
  const g = ctx.createGain();
  osc.type = type;
  osc.frequency.setValueAtTime(freq, startTime);
  if(glideTo != null) osc.frequency.exponentialRampToValueAtTime(Math.max(1,glideTo), startTime + duration);
  g.gain.setValueAtTime(0, startTime);
  g.gain.linearRampToValueAtTime(gain, startTime + 0.015);
  g.gain.exponentialRampToValueAtTime(0.0001, startTime + duration);
  osc.connect(g).connect(ctx.destination);
  // Bez tego węzły oscylatora/gaina zostają na stałe podłączone do grafu
  // audio nawet po zakończeniu dźwięku - przy setkach/tysiącach dźwięków w
  // jednej sesji (np. cała bitwa z wieloma rundami) to narastające
  // przeciążenie, które z czasem powoduje zacinanie się dźwięku, aż w końcu
  // AudioContext przestaje nadążać.
  osc.onended = () => { try { osc.disconnect(); g.disconnect(); } catch (e) {} };
  osc.start(startTime);
  osc.stop(startTime + duration + 0.02);
}

/* Tykanie bębna: kliknięcia przyspieszające na starcie i zwalniające pod koniec spinu. */
function playSpinSound(totalMs){
  if(!soundOn) return;
  const ctx = getAudioCtx();
  if(!ctx) return;
  let cancelled = false;
  const start = ctx.currentTime + 0.02;
  const totalSec = totalMs / 1000;
  let t = 0;
  function scheduleTick(){
    if(cancelled || t >= totalSec) return;
    const progress = t / totalSec;
    const interval = 0.04 + 0.14 * (progress*progress);
    playTone(540, start + t, 0.04, {type:"square", gain:0.045});
    t += interval;
    setTimeout(scheduleTick, interval*1000);
  }
  scheduleTick();
  spinSoundStop = () => { cancelled = true; };
}
function stopSpinSound(){
  if(spinSoundStop){ spinSoundStop(); spinSoundStop = null; }
}
function playWinSound(){
  if(!soundOn) return;
  const ctx = getAudioCtx();
  if(!ctx) return;
  const start = ctx.currentTime + 0.02;
  [523.25, 659.25, 783.99, 1046.5].forEach((f, idx) => {
    playTone(f, start + idx*0.09, 0.28, {type:"triangle", gain:0.22});
  });
}
/* "Kasa" przy sprzedaży przedmiotu w ekwipunku - krótkie, metaliczne "cha-ching". */
function playSellSound(){
  if(!soundOn) return;
  const ctx = getAudioCtx();
  if(!ctx) return;
  const start = ctx.currentTime + 0.02;
  playTone(880, start, 0.09, {type:"square", gain:0.14});
  playTone(1318.5, start + 0.06, 0.22, {type:"triangle", gain:0.18});
}
