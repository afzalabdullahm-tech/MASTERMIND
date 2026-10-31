'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const script=fs.readFileSync(__dirname+'/../assets/js/game.js','utf8');
const handlers={};
const cells=Array.from({length:9},()=>({textContent:'',disabled:false,classList:{toggle:()=>{}},addEventListener(type,fn){this.listener=fn;}}));
const elems={gameStatus:{textContent:'Your turn · You’re X'},humanScore:{textContent:''},botScore:{textContent:''},newRound:{addEventListener:(type,fn)=>handlers.newRound=fn},resetScores:{addEventListener:(type,fn)=>handlers.resetScores=fn}};
const grid={querySelectorAll:()=>cells};
const document={getElementById:id=>id==='ticGrid'?grid:elems[id]};
const storage=new Map();const queue=[];
const ctx={document,window:{},localStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v)},setTimeout:fn=>queue.push(fn)};
vm.createContext(ctx);vm.runInContext(script,ctx);
assert.equal(elems.gameStatus.textContent,'Your turn · You’re X');
assert.equal(elems.humanScore.textContent,'0');
// A valid move should trigger an actual unbeatable computer response.
cells[0].listener();assert.equal(cells[0].textContent,'X');assert.equal(queue.length,1);queue.shift()();
assert.equal(cells.filter(c=>c.textContent==='O').length,1);
assert.equal(elems.gameStatus.textContent,'Your turn · You’re X');
handlers.newRound();assert.equal(cells.every(c=>!c.textContent),true);
// Resetting during bot thinking must cancel the stale asynchronous bot move.
cells[3].listener();handlers.newRound();queue.shift()();assert.equal(cells.every(c=>!c.textContent),true);
// Stored scores must reset.
handlers.resetScores();assert.equal(elems.botScore.textContent,'0');assert.equal(elems.humanScore.textContent,'0');
console.log('PASS: playable game, computer turn, round reset, stale turn prevention, score reset');
