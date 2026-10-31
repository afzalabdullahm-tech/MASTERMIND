'use strict';
(()=>{
 const grid=document.getElementById('ticGrid');if(!grid)return;
 const cells=[...grid.querySelectorAll('button')],status=document.getElementById('gameStatus');
 let board=Array(9).fill(''),over=false,busy=false,round=0;
 const scores=JSON.parse(localStorage.getItem('little-game-scores')||'{"human":0,"bot":0}');
 const wins=[[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
 function winner(b){for(const [a,c,d] of wins){if(b[a]&&b[a]===b[c]&&b[c]===b[d])return b[a];}return b.every(Boolean)?'tie':null;}
 function minimax(b,isBot,depth){let w=winner(b);if(w)return {score:w==='O'?10-depth:w==='X'?depth-10:0};let best={score:isBot?-Infinity:Infinity,index:-1};for(let i=0;i<9;i++)if(!b[i]){b[i]=isBot?'O':'X';const next=minimax(b,!isBot,depth+1);b[i]='';if((isBot&&next.score>best.score)||(!isBot&&next.score<best.score))best={score:next.score,index:i};}return best;}
 function render(){cells.forEach((c,i)=>{c.textContent=board[i];c.classList.toggle('x',board[i]==='X');c.classList.toggle('o',board[i]==='O');c.disabled=over||busy||!!board[i];});document.getElementById('humanScore').textContent=scores.human;document.getElementById('botScore').textContent=scores.bot;}
 function finish(){let w=winner(board);if(w){over=true;if(w==='X'){scores.human++;status.textContent='You won! Beautiful move.';}else if(w==='O'){scores.bot++;status.textContent='The computer wins this time.';}else status.textContent="It's a draw. Another round?";localStorage.setItem('little-game-scores',JSON.stringify(scores));}render();}
 cells.forEach((c,i)=>c.addEventListener('click',()=>{if(busy||over||board[i])return;board[i]='X';status.textContent='Thinking…';finish();if(over)return;busy=true;render();const thisRound=round;setTimeout(()=>{if(thisRound!==round)return;let move=minimax(board,true,0).index;if(move>=0)board[move]='O';busy=false;status.textContent='Your turn · You’re X';finish();},420);}));
 function reset(){round++;board=Array(9).fill('');over=false;busy=false;status.textContent='Your turn · You’re X';render();}
 document.getElementById('newRound').addEventListener('click',reset);
 document.getElementById('resetScores').addEventListener('click',()=>{scores.human=0;scores.bot=0;localStorage.setItem('little-game-scores',JSON.stringify(scores));reset();});render();
})();
