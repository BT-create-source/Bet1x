const axios = require('axios');
const key = 'b26690cf-eff8-40ac-a4cb-ddccae915ac7';
axios.get(`https://api.cricapi.com/v1/currentMatches?apikey=${key}`)
  .then(res => {
    const list = res.data.data || [];
    console.log("Total current matches:", list.length);
    list.slice(0, 5).forEach((m, idx) => {
      console.log(`[Match ${idx}] Name: ${m.name}, ID: ${m.id}, Status: ${m.status}, Score length: ${m.score ? m.score.length : 0}`);
    });
  })
  .catch(err => {
    console.error("API error:", err.message);
  });
